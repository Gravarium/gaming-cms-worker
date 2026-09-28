<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildDiscordIntegration;
use App\Repository\GuildDiscordIntegrationRepository;
use App\Service\DiscordWebhookNotifier;
use App\Service\DiscordWebhookUrlPolicy;
use App\Service\SensitiveDataCipher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DiscordWebhookNotifierSecurityTest extends WebTestCase
{
    public function testAllowedDeliveryDisablesMentionsAndBoundsThePayloadAndRequest(): void
    {
        $client = static::createClient();
        $webhookUrl = 'https://discord.com/api/webhooks/123456/synthetic-token';
        [$guild] = $this->guildWithIntegration($client, $webhookUrl);

        /** @var list<array{method: string, url: string, options: array<string, mixed>}> $requests */
        $requests = [];
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return new MockResponse('', ['http_code' => 204]);
            },
        );

        $success = $this->notifier($client, $httpClient)->notify(
            $guild,
            'guild_event',
            str_repeat('T', 1200),
            str_repeat('M', 1200),
        );

        self::assertTrue($success);
        self::assertCount(1, $requests);
        $request = $requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame($webhookUrl, $request['url']);
        self::assertSame(0, $request['options']['max_redirects'] ?? null);
        self::assertSame(4.0, $request['options']['timeout'] ?? null);

        $payloadBody = $request['options']['body'] ?? null;
        self::assertIsString($payloadBody);
        $payload = json_decode($payloadBody, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(['parse' => []], $payload['allowed_mentions'] ?? null);

        $content = $payload['content'] ?? null;
        self::assertIsString($content);
        self::assertLessThanOrEqual(1900, mb_strwidth($content));
    }

    public function testUnapprovedWebhookHostIsRejectedBeforeTheHttpClientIsCalled(): void
    {
        $client = static::createClient();
        [$guild] = $this->guildWithIntegration(
            $client,
            'https://discord.com.attacker.invalid/api/webhooks/123456/synthetic-token',
        );

        /** @var list<array{method: string, url: string, options: array<string, mixed>}> $requests */
        $requests = [];
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return new MockResponse('', ['http_code' => 204]);
            },
        );

        $success = $this->notifier($client, $httpClient)->notify($guild, 'guild_event', 'Test', 'Message');

        self::assertFalse($success);
        self::assertSame([], $requests);
    }

    public function testDisabledIntegrationAndEventPreferencesDoNotSend(): void
    {
        $client = static::createClient();
        $webhookUrl = 'https://discord.com/api/webhooks/123456/synthetic-token';
        [$guild, $integration] = $this->guildWithIntegration($client, $webhookUrl);

        /** @var list<array{method: string, url: string, options: array<string, mixed>}> $requests */
        $requests = [];
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return new MockResponse('', ['http_code' => 204]);
            },
        );
        $notifier = $this->notifier($client, $httpClient);

        $integration->setNotifyEvents(false);
        $this->entityManager($client)->flush();
        self::assertFalse($notifier->notify($guild, 'guild_event', 'Event', 'Message'));

        $integration->setNotifyEvents(true)->setNotifyAnnouncements(false);
        $this->entityManager($client)->flush();
        self::assertFalse($notifier->notify($guild, 'guild_announcement', 'Announcement', 'Message'));

        $integration->setNotifyAnnouncements(true)->setEnabled(false);
        $this->entityManager($client)->flush();
        self::assertFalse($notifier->notify($guild, 'guild_event', 'Event', 'Message'));

        self::assertSame([], $requests);
    }

    public function testRedirectResponseIsNotReportedAsSuccessfulDelivery(): void
    {
        $client = static::createClient();
        $webhookUrl = 'https://discord.com/api/webhooks/123456/synthetic-token';
        [$guild] = $this->guildWithIntegration($client, $webhookUrl);

        /** @var list<array{method: string, url: string, options: array<string, mixed>}> $requests */
        $requests = [];
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://attacker.invalid/']]);
            },
        );

        $success = $this->notifier($client, $httpClient)->notify($guild, 'guild_event', 'Event', 'Message');

        self::assertFalse($success);
        self::assertCount(1, $requests);
        self::assertSame(0, $requests[0]['options']['max_redirects'] ?? null);
    }

    public function testCorruptedStoredCiphertextIsRejectedBeforeTheHttpClientIsCalled(): void
    {
        $client = static::createClient();
        [$guild, $integration] = $this->guildWithIntegration(
            $client,
            'https://discord.com/api/webhooks/123456/synthetic-token',
        );
        $integration->setEncryptedWebhookUrl('not-valid-base64');
        $this->entityManager($client)->flush();

        /** @var list<array{method: string, url: string, options: array<string, mixed>}> $requests */
        $requests = [];
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return new MockResponse('', ['http_code' => 204]);
            },
        );

        $success = $this->notifier($client, $httpClient)->notify($guild, 'guild_event', 'Event', 'Message');

        self::assertFalse($success);
        self::assertSame([], $requests);
    }

    /**
     * @return array{Guild, GuildDiscordIntegration}
     */
    private function guildWithIntegration(
        KernelBrowser $client,
        string $webhookUrl,
        bool $enabled = true,
        bool $notifyEvents = true,
        bool $notifyAnnouncements = true,
    ): array {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())->setName('Webhook game '.$suffix)->setSlug('webhook-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Webhook guild '.$suffix)
            ->setSlug('webhook-guild-'.$suffix)
            ->setServerName('Synthetic server')
            ->setDescription('Synthetic webhook test guild');
        $cipher = $client->getContainer()->get(SensitiveDataCipher::class);
        $integration = (new GuildDiscordIntegration())
            ->setGuild($guild)
            ->setEncryptedWebhookUrl($cipher->encrypt($webhookUrl))
            ->setEnabled($enabled)
            ->setNotifyEvents($notifyEvents)
            ->setNotifyAnnouncements($notifyAnnouncements);

        $entityManager = $this->entityManager($client);
        $entityManager->persist($game);
        $entityManager->persist($guild);
        $entityManager->persist($integration);
        $entityManager->flush();

        return [$guild, $integration];
    }

    private function notifier(KernelBrowser $client, HttpClientInterface $httpClient): DiscordWebhookNotifier
    {
        $container = $client->getContainer();

        return new DiscordWebhookNotifier(
            $container->get(GuildDiscordIntegrationRepository::class),
            $container->get(SensitiveDataCipher::class),
            $container->get(DiscordWebhookUrlPolicy::class),
            $httpClient,
            new NullLogger(),
        );
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}

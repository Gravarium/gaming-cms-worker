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
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DiscordWebhookNotifierFailureSecurityTest extends WebTestCase
{
    public function testHttpErrorFailsClosedWithoutRetryingOrLoggingWebhookUrl(): void
    {
        $client = static::createClient();
        $webhookUrl = 'https://discord.com/api/webhooks/234567/synthetic-failure-token';
        [$guild] = $this->guildWithIntegration($client, $webhookUrl);

        /** @var list<array{method: string, url: string, options: array<string, mixed>}> $requests */
        $requests = [];
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

                return new MockResponse('', ['http_code' => 503]);
            },
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'Discord webhook returned an error status.',
                self::callback(static function (array $context) use ($webhookUrl): bool {
                    $encodedContext = serialize($context);

                    return array_keys($context) === ['guild_id', 'status']
                        && ($context['status'] ?? null) === 503
                        && !str_contains($encodedContext, $webhookUrl);
                }),
            );

        $success = $this->notifier($client, $httpClient, $logger)->notify($guild, 'guild_event', 'Event', 'Message');

        self::assertFalse($success);
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame($webhookUrl, $requests[0]['url']);
    }

    public function testTransportExceptionFailsClosedWithoutRetryingOrLoggingItsMessage(): void
    {
        $client = static::createClient();
        $webhookUrl = 'https://discord.com/api/webhooks/345678/synthetic-transport-token';
        $exceptionMessage = 'Synthetic transport failure for '.$webhookUrl;
        [$guild] = $this->guildWithIntegration($client, $webhookUrl);

        /** @var list<array{method: string, url: string, options: array<string, mixed>}> $requests */
        $requests = [];
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests, $exceptionMessage): MockResponse {
                $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

                throw new TransportException($exceptionMessage);
            },
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'Discord webhook delivery failed.',
                self::callback(static function (array $context) use ($webhookUrl, $exceptionMessage): bool {
                    $encodedContext = serialize($context);

                    return array_keys($context) === ['guild_id', 'exception']
                        && ($context['exception'] ?? null) === TransportException::class
                        && !str_contains($encodedContext, $webhookUrl)
                        && !str_contains($encodedContext, $exceptionMessage);
                }),
            );

        $success = $this->notifier($client, $httpClient, $logger)->notify($guild, 'guild_event', 'Event', 'Message');

        self::assertFalse($success);
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame($webhookUrl, $requests[0]['url']);
    }

    /**
     * @return array{Guild, GuildDiscordIntegration}
     */
    private function guildWithIntegration(KernelBrowser $client, string $webhookUrl): array
    {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())->setName('Webhook failure game '.$suffix)->setSlug('webhook-failure-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Webhook failure guild '.$suffix)
            ->setSlug('webhook-failure-guild-'.$suffix)
            ->setServerName('Synthetic server')
            ->setDescription('Synthetic webhook failure test guild');
        $cipher = $client->getContainer()->get(SensitiveDataCipher::class);
        $integration = (new GuildDiscordIntegration())
            ->setGuild($guild)
            ->setEncryptedWebhookUrl($cipher->encrypt($webhookUrl))
            ->setEnabled(true)
            ->setNotifyEvents(true)
            ->setNotifyAnnouncements(true);

        $entityManager = $this->entityManager($client);
        $entityManager->persist($game);
        $entityManager->persist($guild);
        $entityManager->persist($integration);
        $entityManager->flush();

        return [$guild, $integration];
    }

    private function notifier(
        KernelBrowser $client,
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
    ): DiscordWebhookNotifier {
        $container = $client->getContainer();

        return new DiscordWebhookNotifier(
            $container->get(GuildDiscordIntegrationRepository::class),
            $container->get(SensitiveDataCipher::class),
            $container->get(DiscordWebhookUrlPolicy::class),
            $httpClient,
            $logger,
        );
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}

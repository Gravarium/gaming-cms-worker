<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Entity\VideoWorkspace\VideoSource;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoProviderAuditControllerTest extends WebTestCase
{
    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $db->executeStatement("DELETE FROM video_workspace_source WHERE label LIKE 'video-provider-audit-test-%'");
            $db->executeStatement("DELETE FROM cms_user WHERE email LIKE 'video-provider-audit-test-%'");
            $db->executeStatement('UPDATE cms_module_state SET enabled = ? WHERE module_key = ?', [true, 'video']);

        }

        parent::tearDown();
    }

    public function testAuditRequiresVideoManagerAndEnabledModule(): void
    {
        $client = $this->client();
        $client->loginUser($this->user($client));
        $client->request('GET', '/admin/video-provider-audit');
        self::assertResponseStatusCodeSame(403);

        $client->getCookieJar()->clear();
        $client->loginUser($this->user($client, true));
        $client->request('GET', '/admin/video-provider-audit');
        self::assertResponseIsSuccessful();

        $state = $this->em($client)->find(CmsModuleState::class, 'video');
        self::assertInstanceOf(CmsModuleState::class, $state);
        $state->setEnabled(false);
        $this->em($client)->flush();

        $client->request('GET', '/admin/video-provider-audit');
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testCursorPaginationClassifiesLocallyAndNeverReturnsLabelsUrlsOrTokens(): void
    {
        $client = $this->client();
        $client->loginUser($this->user($client, true));
        $em = $this->em($client);

        $first = $this->source($em, 'mp4', 'https://cdn.example.test/movie.mp4?token=private-audit-token', true);
        $second = $this->source($em, 'youtube', 'https://www.youtube.com/embed/abcdef12345', false);
        $third = $this->source($em, 'youtube', 'https://youtube.com.attacker.test/embed/abcdef12345', true);
        $fourth = $this->source($em, 'missing-provider', 'https://cdn.example.test/movie.mp4', true);
        $before = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM video_workspace_source');

        $client->request('GET', '/admin/video-provider-audit?limit=2');
        self::assertResponseIsSuccessful();
        $page = $this->json($client);
        self::assertSame(2, $page['limit']);
        self::assertTrue($page['has_more']);
        self::assertSame($second->getId(), $page['next_cursor']);
        self::assertSame([
            ['source_id' => $first->getId(), 'provider' => 'mp4', 'enabled' => true, 'authorized' => true, 'status' => 'configured', 'mode' => 'video'],
            ['source_id' => $second->getId(), 'provider' => 'youtube', 'enabled' => false, 'authorized' => true, 'status' => 'disabled', 'mode' => 'iframe'],
        ], $page['items']);

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('private-audit-token', $body);
        self::assertStringNotContainsString('video-provider-audit-test-', $body);
        self::assertStringNotContainsString('movie.mp4', $body);
        self::assertArrayNotHasKey('url', $page['items'][0]);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('nosniff', (string) $client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('werden nicht kontaktiert', $page['note']);

        $client->request('GET', '/admin/video-provider-audit?limit=2&after='.$page['next_cursor']);
        self::assertResponseIsSuccessful();
        $next = $this->json($client);
        self::assertFalse($next['has_more']);
        self::assertNull($next['next_cursor']);
        self::assertSame([$third->getId(), $fourth->getId()], array_column($next['items'], 'source_id'));
        self::assertSame(['invalid_link', 'unknown_provider'], array_column($next['items'], 'status'));
        self::assertSame($before, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM video_workspace_source'));
    }

    public function testInvalidPaginationReturnsNonCachingBadRequest(): void
    {
        $client = $this->client();
        $client->loginUser($this->user($client, true));

        foreach ([
            '/admin/video-provider-audit?limit=101',
            '/admin/video-provider-audit?after=0001',
            '/admin/video-provider-audit?limit%5B%5D=1',
        ] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(400);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringNotContainsString('101', (string) $client->getResponse()->getContent());
        }
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $state = $this->em($client)->find(CmsModuleState::class, 'video');
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('video')->updateVersion('test');
            $this->em($client)->persist($state);
        }
        $state->setEnabled(true);
        $this->em($client)->flush();

        return $client;
    }

    private function user(KernelBrowser $client, bool $manager = false): User
    {
        $user = (new User())
            ->setEmail('video-provider-audit-test-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Video audit test')
            ->setPassword('unused')
            ->verifyEmail()
            ->setPermissions($manager ? [CmsPermission::VIDEO] : []);
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function source(EntityManagerInterface $em, string $provider, string $url, bool $enabled): VideoSource
    {
        $marker = 'video-provider-audit-test-'.bin2hex(random_bytes(6));
        $source = new VideoSource();
        $source->configure($marker, $provider, $url, 0, $enabled, true);
        $em->persist($source);
        $em->flush();

        return $source;
    }

    /** @return array<string,mixed> */
    private function json(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Entity\VideoWorkspace\VideoSource;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoProviderAuditPageControllerTest extends WebTestCase
{
    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $db->executeStatement("DELETE FROM video_workspace_source WHERE label LIKE 'video-provider-audit-page-test-%'");
            $db->executeStatement("DELETE FROM cms_user WHERE email LIKE 'video-provider-audit-page-test-%'");
            $db->executeStatement('UPDATE cms_module_state SET enabled = ? WHERE module_key = ?', [true, 'video']);
        }

        parent::tearDown();
    }

    public function testPageRequiresVideoManagerAndEnabledModule(): void
    {
        $client = $this->client();
        $client->loginUser($this->user($client, false));
        $client->request('GET', '/admin/video-provider-audit/page');
        self::assertResponseStatusCodeSame(403);

        $client->getCookieJar()->clear();
        $client->loginUser($this->user($client, true));
        $client->request('GET', '/admin/video-provider-audit/page');
        self::assertResponseIsSuccessful();

        $state = $this->em($client)->find(CmsModuleState::class, 'video');
        self::assertInstanceOf(CmsModuleState::class, $state);
        $state->setEnabled(false);
        $this->em($client)->flush();

        $client->request('GET', '/admin/video-provider-audit/page');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPagePaginatesLocallyWithoutReturningSavedLabelsUrlsOrTokens(): void
    {
        $client = $this->client();
        $client->loginUser($this->user($client, true));
        $em = $this->em($client);
        $cursor = (int) $em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM video_workspace_source');

        $sources = [
            $this->source($em, 'mp4', 'https://cdn.example.test/movie.mp4?token=private-audit-page-token', true),
            $this->source($em, 'mp4', 'https://cdn.example.test/disabled.mp4', false),
            $this->source($em, 'youtube', 'https://youtube.com.attacker.test/embed/abcdef12345', true),
            $this->source($em, 'missing-provider', 'https://cdn.example.test/unknown.mp4', true),
        ];
        $em->flush();
        $countBeforeRequests = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM video_workspace_source');

        $client->request('GET', '/admin/video-provider-audit/page?limit=2&after='.$cursor);
        self::assertResponseIsSuccessful();
        self::assertSame('private, no-store', $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSelectorTextContains('tbody tr:nth-child(1) td:nth-child(5)', 'Konfiguriert');
        self::assertSelectorTextContains('tbody tr:nth-child(2) td:nth-child(5)', 'Deaktiviert');
        self::assertSelectorExists('a[rel="next"]');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('video-provider-audit-page-test-', $body);
        self::assertStringNotContainsString('private-audit-page-token', $body);
        self::assertStringNotContainsString('movie.mp4', $body);
        self::assertStringNotContainsString('https://cdn.example.test', $body);

        $next = $client->getCrawler()->filter('a[rel="next"]')->link();
        $client->click($next);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody tr:nth-child(1) td:nth-child(5)', 'Ungültiger Link');
        self::assertSelectorTextContains('tbody tr:nth-child(2) td:nth-child(5)', 'Unbekannter Anbieter');
        self::assertSelectorNotExists('a[rel="next"]');
        self::assertSame($countBeforeRequests, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM video_workspace_source'));
        self::assertSame(
            [$sources[2]->getId(), $sources[3]->getId()],
            array_map('intval', $client->getCrawler()->filter('tbody tr')->each(static fn ($row) => trim($row->filter('td')->first()->text()))),
        );
    }

    public function testMalformedPaginationReturnsPrivateBadRequest(): void
    {
        $client = $this->client();
        $client->loginUser($this->user($client, true));

        foreach ([
            '/admin/video-provider-audit/page?limit=101',
            '/admin/video-provider-audit/page?after=0001',
            '/admin/video-provider-audit/page?limit%5B%5D=1',
        ] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(400);
            self::assertSame('private, no-store', $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        }
    }

    private function client(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
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

    private function user(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, bool $manager): User
    {
        $user = (new User())
            ->setEmail('video-provider-audit-page-test-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Video audit page test')
            ->setPassword('unused-test-hash')
            ->verifyEmail()
            ->setPermissions($manager ? [CmsPermission::VIDEO] : [CmsPermission::CONTENT]);
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function source(EntityManagerInterface $em, string $provider, string $url, bool $enabled): VideoSource
    {
        static $counter = 0;
        ++$counter;
        $source = new VideoSource();
        $source->configure('video-provider-audit-page-test-'.bin2hex(random_bytes(6)), $provider, $url, $counter, $enabled, true);
        $em->persist($source);

        return $source;
    }

    private function em(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}

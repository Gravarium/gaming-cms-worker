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

final class VideoProviderAuditCsvControllerTest extends WebTestCase
{
    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $db->executeStatement("DELETE FROM video_workspace_source WHERE label LIKE 'video-provider-audit-csv-test-%'");
            $db->executeStatement("DELETE FROM cms_user WHERE email LIKE 'video-provider-audit-csv-test-%'");
            $db->executeStatement('UPDATE cms_module_state SET enabled = ? WHERE module_key = ?', [true, 'video']);
        }

        parent::tearDown();
    }

    public function testExportRequiresVideoManagerAndEnabledModule(): void
    {
        $client = $this->client();
        $client->loginUser($this->user($client, false));
        $client->request('GET', '/admin/video-provider-audit/export.csv');
        self::assertResponseStatusCodeSame(403);

        $client->getCookieJar()->clear();
        $client->loginUser($this->user($client, true));
        $client->request('GET', '/admin/video-provider-audit/export.csv');
        self::assertResponseIsSuccessful();

        $state = $this->em($client)->find(CmsModuleState::class, 'video');
        self::assertInstanceOf(CmsModuleState::class, $state);
        $state->setEnabled(false);
        $this->em($client)->flush();

        $client->request('GET', '/admin/video-provider-audit/export.csv');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCsvPagesContainOnlyLocalAuditFieldsAndDoNotWriteSources(): void
    {
        $client = $this->client();
        $client->loginUser($this->user($client, true));
        $em = $this->em($client);
        $cursor = (int) $em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM video_workspace_source');

        $sources = [
            $this->source($em, 'mp4', 'https://cdn.example.test/movie.mp4?token=private-audit-csv-token', true),
            $this->source($em, 'mp4', 'https://cdn.example.test/disabled.mp4', false),
            $this->source($em, 'youtube', 'https://youtube.com.attacker.test/embed/abcdef12345', true),
            $this->source($em, 'missing-provider', 'https://cdn.example.test/unknown.mp4', true),
        ];
        $em->flush();
        $countBeforeRequests = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM video_workspace_source');

        $client->request('GET', '/admin/video-provider-audit/export.csv?limit=2&after='.$cursor);
        self::assertResponseIsSuccessful();
        self::assertSame('text/csv; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('video-provider-audit.csv', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame('true', $client->getResponse()->headers->get('X-Video-Audit-Has-More'));
        self::assertSame((string) $sources[1]->getId(), $client->getResponse()->headers->get('X-Video-Audit-Next-Cursor'));

        $firstPage = $this->csvRows((string) $client->getResponse()->getContent());
        self::assertSame(['source_id', 'provider', 'enabled', 'authorized', 'status', 'mode'], array_shift($firstPage));
        self::assertSame([
            [(string) $sources[0]->getId(), 'mp4', 'true', 'true', 'configured', 'video'],
            [(string) $sources[1]->getId(), 'mp4', 'false', 'true', 'disabled', 'video'],
        ], $firstPage);

        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('video-provider-audit-csv-test-', $body);
        self::assertStringNotContainsString('private-audit-csv-token', $body);
        self::assertStringNotContainsString('movie.mp4', $body);
        self::assertStringNotContainsString('https://cdn.example.test', $body);

        $client->request('GET', '/admin/video-provider-audit/export.csv?limit=2&after='.$client->getResponse()->headers->get('X-Video-Audit-Next-Cursor'));
        self::assertResponseIsSuccessful();
        self::assertSame('false', $client->getResponse()->headers->get('X-Video-Audit-Has-More'));
        self::assertFalse($client->getResponse()->headers->has('X-Video-Audit-Next-Cursor'));
        $secondPage = $this->csvRows((string) $client->getResponse()->getContent());
        self::assertSame(['source_id', 'provider', 'enabled', 'authorized', 'status', 'mode'], array_shift($secondPage));
        self::assertSame([
            [(string) $sources[2]->getId(), 'youtube', 'true', 'true', 'invalid_link', ''],
            [(string) $sources[3]->getId(), 'missing-provider', 'true', 'true', 'unknown_provider', ''],
        ], $secondPage);
        self::assertSame($countBeforeRequests, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM video_workspace_source'));
    }

    public function testMalformedPaginationReturnsPrivateBadRequest(): void
    {
        $client = $this->client();
        $client->loginUser($this->user($client, true));

        foreach ([
            '/admin/video-provider-audit/export.csv?limit=101',
            '/admin/video-provider-audit/export.csv?after=0001',
            '/admin/video-provider-audit/export.csv?after=2147483648',
            '/admin/video-provider-audit/export.csv?limit%5B%5D=1',
        ] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(400);
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
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

    private function user(KernelBrowser $client, bool $manager): User
    {
        $user = (new User())
            ->setEmail('video-provider-audit-csv-test-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Video audit CSV test')
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
        $source->configure('video-provider-audit-csv-test-'.bin2hex(random_bytes(6)), $provider, $url, $counter, $enabled, true);
        $em->persist($source);

        return $source;
    }

    /** @return list<list<string>> */
    private function csvRows(string $csv): array
    {
        $lines = array_filter(explode("\r\n", rtrim($csv, "\r\n")), static fn (string $line): bool => $line !== '');

        return array_values(array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), $lines));
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}

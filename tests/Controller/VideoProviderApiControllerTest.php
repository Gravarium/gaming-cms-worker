<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoProviderApiControllerTest extends WebTestCase
{
    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $db->executeStatement("DELETE FROM cms_user WHERE email LIKE 'provider-api-test-%'");
            $db->executeStatement('UPDATE cms_module_state SET enabled = ? WHERE module_key = ?', [true, 'video']);
        }
        parent::tearDown();
    }

    public function testOnlyVideoManagersCanReadTheCompleteCatalogue(): void
    {
        $client = $this->client();
        $client->request('GET', '/admin/video-provider-api');
        self::assertResponseRedirects('/login');
        $client->loginUser($this->user($client));
        $client->request('GET', '/admin/video-provider-api');
        self::assertResponseStatusCodeSame(403);
        $client->getCookieJar()->clear();
        $client->loginUser($this->user($client, true));
        $client->request('GET', '/admin/video-provider-api');
        self::assertResponseIsSuccessful();
        $body = $this->json($client);
        self::assertSame(1, $body['version']);
        self::assertCount(29, $body['providers']);
        self::assertSame('embed_or_direct', $body['providers']['kinescope']['capability']);
        self::assertSame('link', $body['providers']['vdohide']['capability']);
        self::assertArrayHasKey('peertube', $body['providers']);
        self::assertArrayHasKey('dailymotion', $body['providers']);
        self::assertNotEmpty($body['csrf_token']);
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testResolutionIsCsrfProtectedCanonicalAndDoesNotPersist(): void
    {
        $client = $this->client();
        $client->loginUser($this->user($client, true));
        $client->request('GET', '/admin/video-provider-api');
        $token = $this->json($client)['csrf_token'];
        $url = '/admin/video-provider-api/resolve';
        $db = $client->getContainer()->get(EntityManagerInterface::class)->getConnection();
        $before = (int) $db->fetchOne('SELECT COUNT(*) FROM video_workspace_source');

        $client->request('POST', $url, ['provider' => 'kinescope', 'url' => 'https://kinescope.io/202589431', '_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $url, ['provider' => 'kinescope', 'url' => 'https://kinescope.io/202589431', '_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame(['provider' => 'kinescope', 'mode' => 'iframe', 'url' => 'https://kinescope.io/embed/202589431'], $this->json($client));
        $client->request('POST', $url, ['provider' => 'internetarchive', 'url' => 'https://archive.org/download/example-video/clip.mp4', '_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSame('video', $this->json($client)['mode']);
        self::assertSame($before, (int) $db->fetchOne('SELECT COUNT(*) FROM video_workspace_source'));
    }

    public function testUnsupportedAndHostLookalikeUrlsFailWithoutEchoingThem(): void
    {
        $client = $this->client(); $client->loginUser($this->user($client, true));
        $client->request('GET', '/admin/video-provider-api'); $token = $this->json($client)['csrf_token'];
        foreach ([
            ['screenpal', 'https://screenpal.com.attacker.test/player/c0jrbPVp0Zm'],
            ['yourimageshare', 'https://yourimageshare.com/ib/notavideo.svg'],
            ['missing', 'https://go.screenpal.com/player/c0jrbPVp0Zm'],
            ['kinescope', 'http://kinescope.io/embed/202589431'],
        ] as [$provider, $source]) {
            $client->request('POST', '/admin/video-provider-api/resolve', ['provider' => $provider, 'url' => $source, '_token' => $token]);
            self::assertResponseStatusCodeSame(422);
            self::assertArrayHasKey('error', $this->json($client));
            self::assertStringNotContainsString($source, (string) $client->getResponse()->getContent());
        }
    }

    public function testDisabledVideoModuleClosesBothRoutes(): void
    {
        $client = $this->client(); $client->loginUser($this->user($client, true));
        $state = $this->em($client)->find(CmsModuleState::class, 'video');
        self::assertInstanceOf(CmsModuleState::class, $state);
        $state->setEnabled(false); $this->em($client)->flush();
        $client->request('GET', '/admin/video-provider-api'); self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/admin/video-provider-api/resolve', ['provider' => 'kinescope']); self::assertResponseStatusCodeSame(404);
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $state = $this->em($client)->find(CmsModuleState::class, 'video');
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('video')->updateVersion('test');
            $this->em($client)->persist($state);
        }
        $state->setEnabled(true); $this->em($client)->flush();

        return $client;
    }

    private function user(KernelBrowser $client, bool $manager = false): User
    {
        $user = (new User())->setEmail('provider-api-test-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Provider API test')->setPassword('unused')->verifyEmail()
            ->setPermissions($manager ? [CmsPermission::VIDEO] : []);
        $this->em($client)->persist($user); $this->em($client)->flush();

        return $user;
    }

    /** @return array<string,mixed> */
    private function json(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function em(KernelBrowser $client): EntityManagerInterface { return $client->getContainer()->get(EntityManagerInterface::class); }
}

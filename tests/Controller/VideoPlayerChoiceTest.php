<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoDiscovery\CreatorProfile;
use App\Entity\VideoDiscovery\VideoDiscoveryProfile;
use App\Entity\VideoWorkspace\VideoSource;
use App\Security\CmsPermission;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class VideoPlayerChoiceTest extends WebTestCase
{
    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $db->executeStatement("DELETE FROM video_workspace_source WHERE label LIKE 'PC test%'");
            $db->executeStatement("DELETE FROM video WHERE slug LIKE 'pc-test-%'");
            $db->executeStatement("DELETE FROM video_discovery_creator WHERE slug LIKE 'pc-test-%'");
            $db->executeStatement("DELETE FROM cms_user WHERE email LIKE 'pc-test-%'");
            $db->executeStatement('UPDATE cms_module_state SET enabled = ? WHERE module_key = ?', [true, 'video']);
        }
        parent::tearDown();
    }

    public function testConsentLoadsOnlySelectedCompatiblePlayerAndRejectsForeignSource(): void
    {
        $client = $this->client(); $video = $this->video($client); $source = $this->source($client, $video);
        $foreign = $this->source($client, $this->video($client));
        $url = '/video-players/videos/'.$video->getSlug(); $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful(); self::assertSelectorNotExists('video'); self::assertSelectorNotExists('iframe'); self::assertSelectorNotExists('[data-branding-guidance]');
        self::assertStringNotContainsString('cdn.example.test', (string) $client->getResponse()->getContent());
        self::assertSelectorExists('select[name="engine"] option[value="videojs"]'); $token = $this->token($crawler);
        $client->request('POST', $url, ['source' => (string) $foreign->getId(), 'engine' => 'native', '_token' => $token]); self::assertResponseStatusCodeSame(404);
        $client->request('POST', $url, ['source' => (string) $source->getId(), 'engine' => 'videojs', '_token' => $token]);
        self::assertResponseIsSuccessful(); self::assertSelectorExists('video[data-choice-player][data-engine="videojs"][data-mode="hls"]'); self::assertSelectorTextContains('[data-branding-guidance="cms"]', 'Branding-Vorschau ein- oder ausschalten');
        self::assertSelectorNotExists('iframe'); self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        $client->request('POST', $url, ['source' => (string) $source->getId(), 'engine' => 'native', '_token' => $token, 'start' => '12', 'speed' => '1.5']);
        self::assertResponseIsSuccessful(); self::assertSelectorExists('video[data-engine="native"][data-start="12"][data-speed="1.5"]');
        $client->request('POST', $url, ['source' => 'legacy', 'engine' => 'videojs', '_token' => $token]); self::assertResponseStatusCodeSame(422);
    }

    public function testYoutubeOptionsAndLegacyFallbackAreWorkingWithoutDirectStreamExtraction(): void
    {
        $client = $this->client(); $video = $this->video($client); $url = '/video-players/videos/'.$video->getSlug();
        $crawler = $client->request('GET', $url); $token = $this->token($crawler);
        $client->request('POST', $url, ['source' => 'legacy', 'engine' => 'youtube', '_token' => $token, 'start' => '42', 'loop' => '1', 'captions' => '1']);
        self::assertResponseIsSuccessful(); self::assertSelectorExists('iframe'); self::assertSelectorNotExists('video'); self::assertSelectorTextContains('[data-branding-guidance="provider"]', 'bestimmt der Anbieter');
        self::assertSelectorTextContains('[data-branding-guidance="provider"]', 'CMS-Branding lässt sich hier nicht ändern');
        self::assertStringContainsString('start=42', (string) $client->getCrawler()->filter('iframe')->attr('src'));
        self::assertStringContainsString('playlist=abcdef12345', (string) $client->getCrawler()->filter('iframe')->attr('src'));
        $client->request('POST', $url, ['source' => 'legacy', 'engine' => 'embed', '_token' => $token]);
        self::assertResponseIsSuccessful(); self::assertSelectorExists('iframe[src="https://www.youtube-nocookie.com/embed/abcdef12345"]');
    }

    public function testLinkOnlyProviderExplainsExternalBrandingAfterConsent(): void
    {
        $client = $this->client();
        $video = $this->video($client);
        $source = $this->source($client, $video, null, 'vidmoly', 'https://vidmoly.me/watch/demo123');
        $url = '/video-players/videos/'.$video->getSlug();
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('video');
        self::assertSelectorNotExists('iframe');
        self::assertSelectorNotExists('[data-branding-guidance]');
        $token = $this->token($crawler);

        $client->request('POST', $url, ['source' => (string) $source->getId(), 'engine' => 'link', '_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-branding-guidance="external"]', 'Branding und Bedienelemente des Anbieters bleiben erhalten');
        self::assertSelectorExists('.workspace-player a[target="_blank"][rel="noopener noreferrer"]');
        self::assertSelectorNotExists('video');
        self::assertSelectorNotExists('iframe');
    }

    public function testCsrfAndMalformedSettingsFailBeforeAnyPlayerIsRendered(): void
    {
        $client = $this->client(); $video = $this->video($client); $url = '/video-players/videos/'.$video->getSlug();
        $crawler = $client->request('GET', $url); $token = $this->token($crawler);
        $client->request('POST', $url, ['source' => 'legacy', 'engine' => 'youtube', '_token' => 'forged']); self::assertResponseRedirects('/login');
        $client->request('POST', $url, ['source' => 'legacy', 'engine' => 'youtube', '_token' => $token, 'start' => '86401']); self::assertResponseStatusCodeSame(422);
        $client->request('POST', $url, ['source' => 'legacy', 'engine' => 'magicplayer', '_token' => $token]); self::assertResponseStatusCodeSame(422);
    }

    public function testPublicationSourceDisableAndCreatorPrivacyAreRecheckedOnEveryPost(): void
    {
        $client = $this->client(); $video = $this->video($client); $source = $this->source($client, $video); $id = $video->getId();
        $url = '/video-players/videos/'.$video->getSlug(); $crawler = $client->request('GET', $url); $token = $this->token($crawler);
        $this->em($client)->getConnection()->executeStatement('UPDATE video_workspace_source SET enabled = ? WHERE id = ?', [false, $source->getId()]);
        $client->request('POST', $url, ['source' => (string) $source->getId(), 'engine' => 'native', '_token' => $token]); self::assertResponseStatusCodeSame(404);
        $this->em($client)->getConnection()->executeStatement('UPDATE video SET published_at = NULL WHERE id = ?', [$id]);
        $client->request('POST', $url, ['source' => 'legacy', 'engine' => 'embed', '_token' => $token]); self::assertResponseStatusCodeSame(404);
    }

    public function testPrivateVideosAndLiveCreatorsAreNotExposedInIndexOrDirectRoutes(): void
    {
        $client = $this->client(); $owner = $this->user($client); $video = $this->video($client); $em = $this->em($client);
        $creator = (new CreatorProfile('PC test private creator', 'pc-test-'.bin2hex(random_bytes(6))))->setOwner($owner)->setVisibility('private');
        $profile = (new VideoDiscoveryProfile($video))->setCreator($creator)->setVisibility('private');
        $em->persist($creator); $em->persist($profile); $em->flush();
        $live = $this->source($client, null, $creator); $url = '/video-players/videos/'.$video->getSlug();
        $client->request('GET', '/video-players'); self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($video->getSlug(), (string) $client->getResponse()->getContent());
        $client->request('GET', $url); self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/video-players/live/'.$live->getId()); self::assertResponseStatusCodeSame(404);
        $fresh = $this->em($client)->find(User::class, $owner->getId()); self::assertInstanceOf(User::class, $fresh); $client->loginUser($fresh);
        $client->request('GET', $url); self::assertResponseIsSuccessful();
        $crawler = $client->request('GET', '/video-players/live/'.$live->getId()); self::assertResponseIsSuccessful();
        $client->request('POST', '/video-players/live/'.$live->getId(), ['source' => (string) $live->getId(), 'engine' => 'videojs', '_token' => $this->token($crawler)]);
        self::assertResponseIsSuccessful(); self::assertSelectorExists('video[data-engine="videojs"]');
    }

    public function testIntegrationInventoryRequiresManagerPermissionAndHasAllSeventeenNames(): void
    {
        $client = $this->client(); $reader = $this->user($client);
        $client->loginUser($reader); $client->request('GET', '/video-players/integrations'); self::assertResponseStatusCodeSame(403);
        $client->getCookieJar()->clear(); $manager = $this->user($client, true); $client->loginUser($manager);
        $client->request('GET', '/video-players/integrations'); self::assertResponseIsSuccessful(); self::assertSelectorCount(17, 'tbody tr');
        self::assertSelectorTextContains('tbody', 'mp_embed_youtube (CONTENIDO)'); self::assertSelectorTextContains('tbody', 'Flowplay (Webflow)');
    }

    public function testWidgetIsRegisteredAndModuleDisableHidesAllSurfaces(): void
    {
        $client = $this->client(); $video = $this->video($client); $live = $this->source($client, null);
        $registry = $client->getContainer()->get(WidgetRegistry::class);
        self::assertNotNull($registry->get('video.player-choice'));
        self::assertTrue($registry->data('video.player-choice', [])['available']);
        $state = $this->em($client)->find(CmsModuleState::class, 'video'); self::assertInstanceOf(CmsModuleState::class, $state);
        $state->setEnabled(false); $this->em($client)->flush();
        foreach (['/video-players', '/video-players/integrations', '/video-players/videos/'.$video->getSlug(), '/video-players/live/'.$live->getId()] as $url) {
            $client->request('GET', $url); self::assertResponseStatusCodeSame(404);
        }
        $client->request('POST', '/video-players/live/'.$live->getId(), ['source' => (string) $live->getId(), 'engine' => 'videojs']); self::assertResponseStatusCodeSame(404);
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient(); $em = $this->em($client); $state = $em->find(CmsModuleState::class, 'video');
        if (!$state instanceof CmsModuleState) { $state = (new CmsModuleState())->setModuleKey('video')->updateVersion('test'); $em->persist($state); }
        $state->setEnabled(true); $em->flush(); return $client;
    }
    private function video(KernelBrowser $client): Video
    {
        $video = (new Video())->setTitle('PC test video')->setSlug('pc-test-'.bin2hex(random_bytes(6)))->setDescription('PC test description')
            ->setSourceType(Video::SOURCE_YOUTUBE)->setSourceUrl('https://youtu.be/abcdef12345')->setPublishedAt(new \DateTimeImmutable('-1 minute'));
        $this->em($client)->persist($video); $this->em($client)->flush(); return $video;
    }
    private function user(KernelBrowser $client, bool $manager = false): User
    {
        $user = (new User())->setEmail('pc-test-'.bin2hex(random_bytes(6)).'@example.test')->setDisplayName('PC test user')->setPassword('unused')->verifyEmail()->setPermissions($manager ? [CmsPermission::VIDEO] : []);
        $this->em($client)->persist($user); $this->em($client)->flush(); return $user;
    }
    private function source(KernelBrowser $client, ?Video $video, ?CreatorProfile $creator = null, string $provider = 'hls', string $url = 'https://cdn.example.test/live/index.m3u8'): VideoSource
    {
        $em = $this->em($client); $source = (new VideoSource())->setVideo($video === null ? null : $em->getReference(Video::class, $video->getId()))
            ->setCreator($creator === null ? null : $em->getReference(CreatorProfile::class, $creator->getId()));
        $source->configure('PC test source', $provider, $url, 0, true, true);
        $em->persist($source); $em->flush(); return $source;
    }
    private function token(Crawler $crawler): string { return (string) $crawler->filter('input[name="_token"]')->first()->attr('value'); }
    private function em(KernelBrowser $client): EntityManagerInterface { return $client->getContainer()->get(EntityManagerInterface::class); }
}

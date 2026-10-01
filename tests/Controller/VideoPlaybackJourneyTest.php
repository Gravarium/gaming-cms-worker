<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoPlaylist;
use App\Entity\VideoDiscovery\CreatorProfile;
use App\Entity\VideoDiscovery\VideoDiscoveryProfile;
use App\Entity\VideoWorkspace\VideoSource;
use App\Security\CmsPermission;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class VideoPlaybackJourneyTest extends WebTestCase
{
    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $db->executeStatement("DELETE FROM video_workspace_source WHERE label LIKE 'VJ test%'");
            $db->executeStatement("DELETE FROM video WHERE slug LIKE 'vj-test-%'");
            $db->executeStatement("DELETE FROM video_playlist WHERE slug LIKE 'vj-test-%'");
            $db->executeStatement("DELETE FROM video_discovery_creator WHERE slug LIKE 'vj-test-%'");
            $db->executeStatement("DELETE FROM cms_user WHERE email LIKE 'vj-test-%'");
            $db->executeStatement('UPDATE cms_module_state SET enabled = ? WHERE module_key = ?', [true, 'video']);
        }
        parent::tearDown();
    }

    public function testPlaylistLoadsOnlyAfterConsentAndAutomaticallyAdvancesToAuthorizedDirectSource(): void
    {
        $client = $this->client(); $list = $this->playlist($client);
        $first = $this->video($client, $list); $second = $this->video($client, $list); $source = $this->source($client, $first); $other = $this->source($client, $second);
        $url = '/video-viewing/playlists/'.$list->getSlug(); $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful(); self::assertSelectorNotExists('video'); self::assertSelectorNotExists('iframe');
        self::assertStringNotContainsString('cdn.example.test', (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('h1', $first->getTitle()); $token = $this->token($crawler);
        $client->request('POST', $url, ['video' => $first->getSlug(), 'source' => (string) $source->getId(), 'engine' => 'native', 'continuous' => '1', '_token' => $token]);
        self::assertResponseIsSuccessful(); self::assertSelectorExists('video[data-journey-player][data-engine="native"]'); self::assertSelectorExists('form[data-next-video]');
        self::assertSame($second->getSlug(), $client->getCrawler()->filter('form[data-next-video] input[name="video"]')->attr('value'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $client->getCrawler()->filter('video')->attr('data-resume-key'));
        $client->request('POST', $url, ['video' => $second->getSlug(), 'source' => 'auto', 'engine' => 'videojs', 'continuous' => '1', 'autoplay' => '1', '_token' => $token]);
        self::assertResponseIsSuccessful(); self::assertSelectorExists('video[data-engine="videojs"][data-autoplay="1"]'); self::assertSelectorNotExists('form[data-next-video]');
        self::assertSelectorTextContains('main', 'Ende der Playlist');
        self::assertSame('https://cdn.example.test/'.$other->getId().'.mp4', $client->getCrawler()->filter('video')->attr('data-source'));
    }

    public function testAutoAdvanceStopsAtProviderAndRequiresContinuousConsent(): void
    {
        $client = $this->client(); $list = $this->playlist($client); $video = $this->video($client, $list); $url = '/video-viewing/playlists/'.$list->getSlug();
        $token = $this->token($client->request('GET', $url));
        $client->request('POST', $url, ['video' => $video->getSlug(), 'source' => 'auto', 'engine' => 'native', 'continuous' => '1', '_token' => $token]);
        self::assertResponseIsSuccessful(); self::assertSelectorNotExists('iframe'); self::assertSelectorNotExists('video');
        self::assertSelectorTextContains('main', 'Anbieterquelle ausdrücklich auswählen');
        $client->request('POST', $url, ['video' => $video->getSlug(), 'source' => 'auto', 'engine' => 'native', '_token' => $token]); self::assertResponseStatusCodeSame(422);
        $client->request('POST', $url, ['video' => $video->getSlug(), 'source' => 'legacy', 'engine' => 'embed', '_token' => $token]);
        self::assertResponseIsSuccessful(); self::assertSelectorExists('iframe'); self::assertSelectorNotExists('form[data-next-video]');
    }

    public function testForeignVideoSourceAndCsrfCannotCrossPlaylistBoundary(): void
    {
        $client = $this->client(); $list = $this->playlist($client); $video = $this->video($client, $list); $foreignVideo = $this->video($client); $foreignSource = $this->source($client, $foreignVideo);
        $url = '/video-viewing/playlists/'.$list->getSlug(); $token = $this->token($client->request('GET', $url));
        $client->request('POST', $url, ['video' => $foreignVideo->getSlug(), 'source' => (string) $foreignSource->getId(), 'engine' => 'native', '_token' => $token]); self::assertResponseStatusCodeSame(404);
        $client->request('POST', $url, ['video' => $video->getSlug(), 'source' => (string) $foreignSource->getId(), 'engine' => 'native', '_token' => $token]); self::assertResponseStatusCodeSame(404);
        $client->request('POST', $url, ['video' => $video->getSlug(), 'source' => 'legacy', 'engine' => 'embed', '_token' => 'forged']); self::assertResponseRedirects('/login');
    }

    public function testChangedPublicationSourceAndPlaylistAreRecheckedOnPost(): void
    {
        $client = $this->client(); $list = $this->playlist($client); $video = $this->video($client, $list); $source = $this->source($client, $video);
        $url = '/video-viewing/playlists/'.$list->getSlug(); $token = $this->token($client->request('GET', $url));
        $this->em($client)->getConnection()->executeStatement('UPDATE video_workspace_source SET authorized = ? WHERE id = ?', [false, $source->getId()]);
        $client->request('POST', $url, ['video' => $video->getSlug(), 'source' => (string) $source->getId(), 'engine' => 'native', '_token' => $token]); self::assertResponseStatusCodeSame(404);
        $this->em($client)->getConnection()->executeStatement('UPDATE video SET published_at = NULL WHERE id = ?', [$video->getId()]);
        $client->request('POST', $url, ['video' => $video->getSlug(), 'source' => 'legacy', 'engine' => 'embed', '_token' => $token]); self::assertResponseStatusCodeSame(404);
        $this->em($client)->getConnection()->executeStatement('UPDATE video_playlist SET enabled = ? WHERE id = ?', [false, $list->getId()]);
        $client->request('GET', $url); self::assertResponseStatusCodeSame(404);
    }

    public function testPrivateCreatorVideosAreSkippedAndOwnerPlaybackDoesNotExposeResumeKey(): void
    {
        $client = $this->client(); $owner = $this->user($client); $list = $this->playlist($client); $private = $this->video($client, $list); $public = $this->video($client, $list); $source = $this->source($client, $private);
        $em = $this->em($client); $creator = (new CreatorProfile('VJ test private', 'vj-test-'.bin2hex(random_bytes(6))))->setOwner($owner)->setVisibility('private');
        // Even a public profile must respect its creator's private visibility.
        $profile = (new VideoDiscoveryProfile($private))->setCreator($creator)->setVisibility('public'); $em->persist($creator); $em->persist($profile); $em->flush();
        $url = '/video-viewing/playlists/'.$list->getSlug(); $client->request('GET', $url); self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $public->getTitle()); self::assertStringNotContainsString($private->getSlug(), (string) $client->getResponse()->getContent());
        $client->request('GET', '/video-viewing/videos/'.$private->getSlug()); self::assertResponseStatusCodeSame(404);
        $fresh = $this->em($client)->find(User::class, $owner->getId()); self::assertInstanceOf(User::class, $fresh); $client->loginUser($fresh);
        $crawler = $client->request('GET', $url.'?video='.$private->getSlug()); self::assertResponseIsSuccessful();
        $client->request('POST', $url, ['video' => $private->getSlug(), 'source' => (string) $source->getId(), 'engine' => 'native', '_token' => $this->token($crawler)]);
        self::assertResponseIsSuccessful(); self::assertSelectorExists('video[data-resume-key=""]'); self::assertSelectorNotExists('[data-resume-opt-in]');
    }

    public function testManagerPreviewWorksBeforePublicationAndHasActionableChecks(): void
    {
        $client = $this->client(); $manager = $this->user($client, true); $video = $this->video($client); $source = $this->source($client, $video);
        $this->em($client)->getConnection()->executeStatement('UPDATE video SET published_at = NULL WHERE id = ?', [$video->getId()]); $client->loginUser($manager);
        $client->request('GET', '/admin/video-playback-check'); self::assertResponseIsSuccessful();
        $url = '/admin/video-playback-check/videos/'.$video->getId(); $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful(); self::assertSelectorNotExists('video'); self::assertSelectorNotExists('iframe'); self::assertSelectorTextContains('main', 'nicht veröffentlicht');
        self::assertStringNotContainsString('cdn.example.test', (string) $client->getResponse()->getContent()); $token = $this->token($crawler);
        $client->request('POST', $url, ['source' => (string) $source->getId(), 'engine' => 'native', '_token' => $token]);
        self::assertResponseIsSuccessful(); self::assertSelectorExists('video[data-journey-player]'); self::assertSelectorNotExists('[data-resume-opt-in]');
        $this->em($client)->getConnection()->executeStatement('UPDATE video_workspace_source SET enabled = ? WHERE id = ?', [false, $source->getId()]);
        $client->request('GET', $url); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('main', 'Quelle deaktiviert');
        $client->request('POST', $url, ['source' => (string) $source->getId(), 'engine' => 'native', '_token' => $token]); self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/video-viewing/videos/'.$video->getSlug()); self::assertResponseStatusCodeSame(404);
    }

    public function testManagerPreviewRejectsForeignSourceBadCsrfAndReader(): void
    {
        $client = $this->client(); $manager = $this->user($client, true); $video = $this->video($client); $foreign = $this->source($client, $this->video($client)); $client->loginUser($manager);
        $url = '/admin/video-playback-check/videos/'.$video->getId(); $token = $this->token($client->request('GET', $url));
        $client->request('POST', $url, ['source' => (string) $foreign->getId(), 'engine' => 'native', '_token' => $token]); self::assertResponseStatusCodeSame(404);
        $client->request('POST', $url, ['source' => 'legacy', 'engine' => 'embed', '_token' => 'forged']); self::assertResponseStatusCodeSame(403);
        $client->getCookieJar()->clear(); $reader = $this->user($client); $client->loginUser($reader);
        $client->request('GET', $url); self::assertResponseStatusCodeSame(403); $client->request('GET', '/admin/video-playback-check'); self::assertResponseStatusCodeSame(403);
    }

    public function testEmptyPlaylistWidgetAndDisabledModule(): void
    {
        $client = $this->client(); $list = $this->playlist($client); $video = $this->video($client); $client->request('GET', '/video-viewing'); self::assertResponseIsSuccessful();
        $client->request('GET', '/video-viewing/playlists/'.$list->getSlug()); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('main', 'keine für dich sichtbaren Videos');
        $registry = $client->getContainer()->get(WidgetRegistry::class); self::assertNotNull($registry->get('video.viewing-journey')); self::assertTrue($registry->data('video.viewing-journey', [])['available']);
        $state = $this->em($client)->find(CmsModuleState::class, 'video'); self::assertInstanceOf(CmsModuleState::class, $state); $state->setEnabled(false); $this->em($client)->flush();
        foreach (['/video-viewing', '/video-viewing/playlists/'.$list->getSlug(), '/video-viewing/videos/'.$video->getSlug()] as $url) { $client->request('GET', $url); self::assertResponseStatusCodeSame(404); }
        $manager = $this->user($client, true); $client->loginUser($manager);
        $client->request('GET', '/admin/video-playback-check'); self::assertResponseStatusCodeSame(404);
        $client->request('POST', '/admin/video-playback-check/videos/'.$video->getId(), ['source' => 'legacy', 'engine' => 'embed']); self::assertResponseStatusCodeSame(404);
    }

    private function client(): KernelBrowser
    {
        $client = static::createClient(); $em = $this->em($client); $state = $em->find(CmsModuleState::class, 'video');
        if (!$state instanceof CmsModuleState) { $state = (new CmsModuleState())->setModuleKey('video')->updateVersion('test'); $em->persist($state); }
        $state->setEnabled(true); $em->flush(); return $client;
    }
    private function playlist(KernelBrowser $client): VideoPlaylist
    {
        $list = (new VideoPlaylist())->setTitle('VJ test playlist')->setSlug('vj-test-'.bin2hex(random_bytes(6))); $this->em($client)->persist($list); $this->em($client)->flush(); return $list;
    }
    private function video(KernelBrowser $client, ?VideoPlaylist $list = null): Video
    {
        $suffix = bin2hex(random_bytes(6)); $video = (new Video())->setTitle('VJ test '.$suffix)->setSlug('vj-test-'.$suffix)->setDescription('VJ test description')
            ->setSourceType(Video::SOURCE_YOUTUBE)->setSourceUrl('https://youtu.be/abcdef12345')->setPublishedAt(new \DateTimeImmutable('-1 minute'));
        if ($list !== null) { $fresh = $this->em($client)->getReference(VideoPlaylist::class, $list->getId()); self::assertInstanceOf(VideoPlaylist::class, $fresh); $video->addPlaylist($fresh); }
        $this->em($client)->persist($video); $this->em($client)->flush(); return $video;
    }
    private function user(KernelBrowser $client, bool $manager = false): User
    {
        $user = (new User())->setEmail('vj-test-'.bin2hex(random_bytes(6)).'@example.test')->setDisplayName('VJ test user')->setPassword('unused')->verifyEmail()->setPermissions($manager ? [CmsPermission::VIDEO] : []);
        $this->em($client)->persist($user); $this->em($client)->flush(); return $user;
    }
    private function source(KernelBrowser $client, Video $video): VideoSource
    {
        $em = $this->em($client); $source = (new VideoSource())->setVideo($em->getReference(Video::class, $video->getId()));
        $source->configure('VJ test MP4', 'mp4', 'https://cdn.example.test/temp.mp4', 0, true, true); $em->persist($source); $em->flush();
        $source->configure('VJ test MP4', 'mp4', 'https://cdn.example.test/'.$source->getId().'.mp4', 0, true, true); $em->flush(); return $source;
    }
    private function token(Crawler $crawler): string { return (string) $crawler->filter('input[name="_token"]')->first()->attr('value'); }
    private function em(KernelBrowser $client): EntityManagerInterface { return $client->getContainer()->get(EntityManagerInterface::class); }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoDiscovery\CreatorProfile;
use App\Entity\VideoDiscovery\VideoClip;
use App\Entity\VideoDiscovery\VideoDiscoveryProfile;
use App\Entity\VideoDiscovery\VideoLiveStream;
use App\Entity\VideoDiscovery\VideoTimestampComment;
use App\Entity\VideoDiscovery\VideoWatchlist;
use App\Entity\VideoDiscovery\VideoWatchlistItem;
use App\Entity\VideoWorkspace\VideoSource;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class VideoWorkspaceTest extends WebTestCase
{
    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $users = "SELECT id FROM cms_user WHERE email LIKE 'vw-test-%'";
            $videos = "SELECT id FROM video WHERE slug LIKE 'vw-test-%'";
            $creators = "SELECT id FROM video_discovery_creator WHERE slug LIKE 'vw-test-%'";
            $db->executeStatement('DELETE FROM video_workspace_source WHERE label LIKE ? OR video_id IN ('.$videos.') OR creator_id IN ('.$creators.')', ['VW test%']);
            $db->executeStatement('DELETE FROM video_discovery_live_stream WHERE title LIKE ? OR creator_id IN ('.$creators.')', ['VW test%']);
            $db->executeStatement('DELETE FROM video_discovery_watchlist_item WHERE watchlist_id IN (SELECT id FROM video_discovery_watchlist WHERE user_id IN ('.$users.'))');
            $db->executeStatement('DELETE FROM video_discovery_watchlist WHERE user_id IN ('.$users.')');
            $db->executeStatement('DELETE FROM video_discovery_timestamp_comment WHERE author_id IN ('.$users.')');
            $db->executeStatement('DELETE FROM video_discovery_clip WHERE created_by_id IN ('.$users.')');
            $db->executeStatement('DELETE FROM video WHERE slug LIKE ?', ['vw-test-%']);
            $db->executeStatement("DELETE FROM video_discovery_tag WHERE name LIKE 'VW test%'");
            $db->executeStatement('DELETE FROM video_discovery_creator WHERE slug LIKE ?', ['vw-test-%']);
            $db->executeStatement('DELETE FROM cms_user WHERE email LIKE ?', ['vw-test-%']);
            $db->executeStatement('UPDATE cms_module_state SET enabled = ? WHERE module_key = ?', [true, 'video']);
        }
        parent::tearDown();
    }
    public function testManagerCanCreateEditAndDeleteAuthorizedVideoSource(): void
    {
        $client = $this->client(); $manager = $this->user($client, true); $video = $this->video($client);
        $this->login($client, $manager); $crawler = $client->request('GET', '/admin/video-workspace/sources/new?video='.$video->getId());
        self::assertResponseIsSuccessful(); self::assertSelectorExists('select[name="provider"] option[value="hls"]');
        $client->request('POST', '/admin/video-workspace/sources/new', $this->sourceFields($video->getId(), $this->token($crawler)));
        self::assertResponseRedirects('/admin/video-workspace');
        $source = $this->em($client)->getRepository(VideoSource::class)->findOneBy(['video' => $video]); self::assertInstanceOf(VideoSource::class, $source);
        $id = $source->getId(); $crawler = $client->request('GET', '/admin/video-workspace/sources/'.$id);
        $fields = $this->sourceFields($video->getId(), $this->token($crawler)); $fields['label'] = 'VW test edited source'; $fields['version'] = $crawler->filter('input[name="version"]')->attr('value');
        $client->request('POST', '/admin/video-workspace/sources/'.$id, $fields); self::assertResponseRedirects();
        $this->em($client)->clear(); self::assertSame('VW test edited source', $this->em($client)->find(VideoSource::class, $id)->getLabel());
        $crawler = $client->request('GET', '/admin/video-workspace/sources/'.$id);
        $client->request('POST', '/admin/video-workspace/sources/'.$id, ['_token' => $this->token($crawler), 'version' => $crawler->filter('input[name="version"]')->attr('value'), 'action' => 'delete']);
        self::assertResponseRedirects(); $this->em($client)->clear(); self::assertNull($this->em($client)->find(VideoSource::class, $id));
        self::assertInstanceOf(Video::class, $this->em($client)->find(Video::class, $video->getId()));
    }
    public function testSourcePermissionsCsrfAuthorizationAndStaleEdit(): void
    {
        $client = $this->client(); $reader = $this->user($client); $manager = $this->user($client, true); $video = $this->video($client);
        $client->request('GET', '/admin/video-workspace'); self::assertResponseRedirects('/login');
        $this->login($client, $reader); $client->request('GET', '/admin/video-workspace'); self::assertResponseStatusCodeSame(403);
        $this->login($client, $manager); $crawler = $client->request('GET', '/admin/video-workspace/sources/new'); self::assertResponseIsSuccessful(); $token = $this->token($crawler);
        $client->request('POST', '/admin/video-workspace/sources/new', $this->sourceFields($video->getId(), 'forged')); self::assertResponseStatusCodeSame(403);
        $fields = $this->sourceFields($video->getId(), $token); $fields['authorized'] = '0';
        $client->request('POST', '/admin/video-workspace/sources/new', $fields); self::assertResponseStatusCodeSame(422);
        self::assertNull($this->em($client)->getRepository(VideoSource::class)->findOneBy(['video' => $video]));
        $client->request('GET', '/admin/video-workspace');
        $source = $this->source($client, $video); $id = $source->getId();
        $crawler = $client->request('GET', '/admin/video-workspace/sources/'.$id); $fields = $this->sourceFields($video->getId(), $this->token($crawler)); $fields['version'] = '0';
        $client->request('POST', '/admin/video-workspace/sources/'.$id, $fields); self::assertResponseStatusCodeSame(409);
    }
    public function testPublicSourceSelectionRequiresConsentAndKeepsForeignAndDraftSourcesHidden(): void
    {
        $client = $this->client(); $video = $this->video($client); $source = $this->source($client, $video); $other = $this->source($client, $this->video($client));
        $url = '/video-workspace/videos/'.$video->getSlug(); $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful(); self::assertSelectorNotExists('video'); self::assertSelectorNotExists('iframe');
        self::assertStringNotContainsString('cdn.example.test', (string) $client->getResponse()->getContent());
        $token = (string) $crawler->filter('form input[name="source"][value="'.$source->getId().'"]')->closest('form')->filter('input[name="_token"]')->first()->attr('value');
        $client->request('POST', $url, ['source' => (string) $other->getId(), '_token' => $token]); self::assertResponseStatusCodeSame(404);
        $client->request('POST', $url, ['source' => (string) $source->getId(), '_token' => 'forged']); self::assertResponseRedirects('/login');
        $client->request('POST', $url, ['source' => (string) $source->getId(), '_token' => $token]); self::assertResponseIsSuccessful(); self::assertSelectorExists('video[data-mode="hls"]');
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        $this->em($client)->find(Video::class, $video->getId())->setPublishedAt(null); $this->em($client)->flush();
        $client->request('POST', $url, ['source' => (string) $source->getId(), '_token' => $token]); self::assertResponseStatusCodeSame(404);
    }
    public function testPrivateProfileAndCreatorAlsoProtectAdditionalSources(): void
    {
        $client = $this->client(); $owner = $this->user($client); $other = $this->user($client); $video = $this->video($client);
        $creator = (new CreatorProfile('VW test private creator', 'vw-test-'.bin2hex(random_bytes(6))))->setOwner($owner)->setVisibility('private');
        $profile = (new VideoDiscoveryProfile($video))->setCreator($creator)->setVisibility('private'); $this->em($client)->persist($creator); $this->em($client)->persist($profile); $this->em($client)->flush();
        $this->source($client, $video); $live = $this->source($client, null, $creator);
        $url = '/video-workspace/videos/'.$video->getSlug(); $client->request('GET', $url); self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/video-workspace/live/'.$live->getId()); self::assertResponseStatusCodeSame(404);
        $this->login($client, $other); $client->request('GET', $url); self::assertResponseStatusCodeSame(404);
        $this->login($client, $owner); $client->request('GET', $url); self::assertResponseIsSuccessful();
        $client->request('GET', '/video-workspace/live/'.$live->getId()); self::assertResponseIsSuccessful();
    }
    public function testWatchlistOwnerCanRenameRemoveItemsAndDeleteWithoutTouchingAnotherList(): void
    {
        $client = $this->client(); $owner = $this->user($client); $other = $this->user($client); $video = $this->video($client);
        $list = new VideoWatchlist($owner, 'VW test list'); $foreign = new VideoWatchlist($other, 'VW test foreign list');
        $item = new VideoWatchlistItem($list, $video); $foreignItem = new VideoWatchlistItem($foreign, $video);
        foreach ([$list, $foreign, $item, $foreignItem] as $entity) { $this->em($client)->persist($entity); } $this->em($client)->flush();
        $url = '/account/video-workspace/watchlist/'.$list->getId(); $this->login($client, $other); $client->request('GET', $url); self::assertResponseStatusCodeSame(404);
        $this->login($client, $owner); $crawler = $client->request('GET', $url); self::assertResponseIsSuccessful(); $fields = $this->ownedFields($crawler); $fields += ['action' => 'edit', 'name' => 'VW test renamed', 'public' => '1'];
        $client->request('POST', $url, $fields); self::assertResponseRedirects('/account/video-workspace');
        self::assertSame('VW test renamed', $this->em($client)->getConnection()->fetchOne('SELECT name FROM video_discovery_watchlist WHERE id = ?', [$list->getId()]));
        $crawler = $client->request('GET', $url); $itemId = $crawler->filter('input[name="item_id"]')->attr('value'); $fields = $this->ownedFields($crawler);
        $client->request('POST', $url, $fields + ['action' => 'remove', 'item_id' => $itemId]); self::assertResponseRedirects();
        self::assertSame(0, (int) $this->em($client)->getConnection()->fetchOne('SELECT COUNT(*) FROM video_discovery_watchlist_item WHERE watchlist_id = ?', [$list->getId()]));
        self::assertSame(1, (int) $this->em($client)->getConnection()->fetchOne('SELECT COUNT(*) FROM video_discovery_watchlist_item WHERE watchlist_id = ?', [$foreign->getId()]));
        $crawler = $client->request('GET', $url); $client->request('POST', $url, $this->ownedFields($crawler) + ['action' => 'delete']); self::assertResponseRedirects();
        $client->request('GET', $url); self::assertResponseStatusCodeSame(404);
    }
    public function testCommentsAndClipsSupportOwnedCorrectionsWithValidationCsrfAndStaleChecks(): void
    {
        $client = $this->client(); $owner = $this->user($client); $video = $this->video($client);
        $comment = new VideoTimestampComment($owner, $video, 12, 'VW test comment'); $clip = new VideoClip($video, $owner, 'VW test clip', 'vw-test-'.bin2hex(random_bytes(6)), 0, 30);
        $this->em($client)->persist($comment); $this->em($client)->persist($clip); $this->em($client)->flush(); $this->login($client, $owner);
        $commentId = (int) $this->em($client)->getConnection()->fetchOne('SELECT id FROM video_discovery_timestamp_comment WHERE author_id = ?', [$owner->getId()]);
        $url = '/account/video-workspace/comment/'.$commentId; $crawler = $client->request('GET', $url); $fields = $this->ownedFields($crawler);
        $client->request('POST', $url, ['_token' => 'forged', 'action' => 'delete']); self::assertResponseStatusCodeSame(403);
        $client->request('POST', $url, $fields + ['body' => 'VW test edited comment', 'timestamp_seconds' => '42', 'visibility' => 'member', 'action' => 'edit']); self::assertResponseRedirects();
        $client->request('POST', $url, $fields + ['body' => 'VW test stale comment', 'timestamp_seconds' => '1', 'visibility' => 'public', 'action' => 'edit']); self::assertResponseStatusCodeSame(409);
        $crawler = $client->request('GET', $url); $client->request('POST', $url, $this->ownedFields($crawler) + ['action' => 'delete']); self::assertResponseRedirects();
        $url = '/account/video-workspace/clip/'.$clip->getId(); $crawler = $client->request('GET', $url); $fields = $this->ownedFields($crawler);
        $client->request('POST', $url, $fields + ['title' => 'VW test invalid', 'start_seconds' => '0', 'end_seconds' => '601', 'visibility' => 'public']); self::assertResponseStatusCodeSame(422);
        $client->request('POST', $url, $fields + ['title' => 'VW test corrected clip', 'start_seconds' => '3', 'end_seconds' => '25', 'visibility' => 'private']); self::assertResponseRedirects();
        self::assertSame('private', $this->em($client)->getConnection()->fetchOne('SELECT visibility FROM video_discovery_clip WHERE id = ?', [$clip->getId()]));
        $crawler = $client->request('GET', $url); $client->request('POST', $url, $this->ownedFields($crawler) + ['action' => 'delete']); self::assertResponseRedirects();
    }
    public function testCreatorAndDiscoveryMetadataAdministrationMakesExistingFeaturesUsable(): void
    {
        $client = $this->client(); $manager = $this->user($client, true); $video = $this->video($client); $this->login($client, $manager);
        $crawler = $client->request('GET', '/admin/video-workspace/creators/new');
        $client->request('POST', '/admin/video-workspace/creators/new', ['_token' => $this->token($crawler), 'display_name' => 'VW test creator', 'bio' => 'VW test bio', 'visibility' => 'private']); self::assertResponseRedirects();
        $creator = $this->em($client)->getRepository(CreatorProfile::class)->findOneBy(['displayName' => 'VW test creator']); self::assertInstanceOf(CreatorProfile::class, $creator);
        $url = '/admin/video-workspace/videos/'.$video->getId().'/metadata'; $crawler = $client->request('GET', $url);
        $client->request('POST', $url, $this->ownedFields($crawler) + ['creator_id' => (string) $creator->getId(), 'visibility' => 'private', 'discoverable' => '1', 'tags' => 'VW test tag']); self::assertResponseRedirects();
        $profile = $this->em($client)->getRepository(VideoDiscoveryProfile::class)->findOneBy(['video' => $video]); self::assertInstanceOf(VideoDiscoveryProfile::class, $profile); self::assertSame('private', $profile->getVisibility()); self::assertCount(1, $profile->getTags());
        $url = '/admin/video-workspace/creators/'.$creator->getId(); $crawler = $client->request('GET', $url);
        $client->request('POST', $url, $this->ownedFields($crawler) + ['action' => 'delete']); self::assertResponseStatusCodeSame(409);
        $crawler = $client->request('GET', $url); $client->request('POST', $url, $this->ownedFields($crawler) + ['display_name' => 'VW test updated creator', 'bio' => 'New description', 'visibility' => 'member']); self::assertResponseRedirects();
        self::assertSame('member', $this->em($client)->getConnection()->fetchOne('SELECT visibility FROM video_discovery_creator WHERE id = ?', [$creator->getId()]));
    }
    public function testLegacyLivestreamCanBeEditedAndDeleted(): void
    {
        $client = $this->client(); $manager = $this->user($client, true); $stream = new VideoLiveStream('VW test legacy', 'twitch', 'https://twitch.tv/example'); $this->em($client)->persist($stream); $this->em($client)->flush(); $id = $stream->getId(); $this->login($client, $manager);
        $url = '/admin/video-workspace/legacy-live/'.$id; $crawler = $client->request('GET', $url);
        $client->request('POST', $url, $this->ownedFields($crawler) + ['title' => 'VW test legacy edited', 'provider' => 'youtube', 'source_url' => 'https://youtu.be/abcdef12345', 'creator_id' => '0', 'starts_at' => '', 'enabled' => '1']); self::assertResponseRedirects();
        self::assertSame('youtube', $this->em($client)->getConnection()->fetchOne('SELECT provider FROM video_discovery_live_stream WHERE id = ?', [$id]));
        $crawler = $client->request('GET', $url); $client->request('POST', $url, $this->ownedFields($crawler) + ['action' => 'delete']); self::assertResponseRedirects();
        self::assertFalse($this->em($client)->getConnection()->fetchOne('SELECT id FROM video_discovery_live_stream WHERE id = ?', [$id]));
    }
    public function testModuleDisableHidesAllWorkspaceSurfacesWithoutMutations(): void
    {
        $client = $this->client(); $manager = $this->user($client, true); $video = $this->video($client); $source = $this->source($client, $video); $this->login($client, $manager);
        $state = $this->em($client)->find(CmsModuleState::class, 'video'); $state->setEnabled(false); $this->em($client)->flush();
        foreach (['/video-workspace', '/video-workspace/videos/'.$video->getSlug(), '/admin/video-workspace', '/account/video-workspace', '/admin/video-workspace/sources/'.$source->getId()] as $url) { $client->request('GET', $url); self::assertResponseStatusCodeSame(404); }
        $client->request('POST', '/admin/video-workspace/sources/'.$source->getId(), ['action' => 'delete']); self::assertResponseStatusCodeSame(404);
        self::assertInstanceOf(VideoSource::class, $this->em($client)->find(VideoSource::class, $source->getId()));
    }
    public function testOriginalVideoRemainsPlayableThroughConsentAndPrivateHeaders(): void
    {
        $client = $this->client(); $video = $this->video($client); $url = '/video-workspace/videos/'.$video->getSlug(); $crawler = $client->request('GET', $url);
        self::assertSelectorNotExists('iframe'); $token = (string) $crawler->filter('input[name="source"][value="legacy"]')->closest('form')->filter('input[name="_token"]')->first()->attr('value');
        $client->request('POST', $url, ['source' => 'legacy', '_token' => $token]); self::assertResponseIsSuccessful(); self::assertSelectorExists('iframe[src="https://www.youtube-nocookie.com/embed/abcdef12345"]');
    }
    public function testManagerSeesReadableProviderCapabilitiesAndBrandingDisclosure(): void
    {
        $client = $this->client();
        $manager = $this->user($client, true);
        $this->login($client, $manager);

        $client->request('GET', '/admin/video-workspace');

        self::assertResponseIsSuccessful();
        self::assertSame('private, no-store', $client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorCount(29, 'table[aria-label="Unterstützte Videoanbieter und Quellformate"] tbody tr');
        self::assertSelectorCount(29, 'table[aria-label="Unterstützte Videoanbieter und Quellformate"] tbody a[target="_blank"][rel="noopener noreferrer"]');
        self::assertSelectorTextContains('table[aria-label="Unterstützte Videoanbieter und Quellformate"]', 'Anbieter-Player (iframe)');
        self::assertSelectorTextContains('table[aria-label="Unterstützte Videoanbieter und Quellformate"]', 'Direkte Datei über den CMS-Player');
        self::assertSelectorTextContains('table[aria-label="Unterstützte Videoanbieter und Quellformate"]', 'Nur externer Link');
        self::assertSelectorTextContains('table[aria-label="Unterstützte Videoanbieter und Quellformate"]', 'VdoHide');
        self::assertSelectorTextContains('details', 'Das CMS legt dort kein eigenes Logo darüber.');
        self::assertSelectorNotExists('table iframe');
        self::assertStringNotContainsString('embed_or_direct', (string) $client->getResponse()->getContent());
    }

    private function login(KernelBrowser $client, User $user): void
    {
        $client->getCookieJar()->clear();
        $freshUser = $this->em($client)->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $freshUser);
        $client->loginUser($freshUser);
    }
    private function client(): KernelBrowser
    {
        $client = static::createClient(); $state = $this->em($client)->find(CmsModuleState::class, 'video');
        if (!$state instanceof CmsModuleState) { $state = (new CmsModuleState())->setModuleKey('video')->updateVersion('test'); $this->em($client)->persist($state); }
        $state->setEnabled(true); $this->em($client)->flush(); return $client;
    }
    private function user(KernelBrowser $client, bool $manager = false): User
    {
        $user = (new User())->setEmail('vw-test-'.bin2hex(random_bytes(6)).'@example.test')->setDisplayName('VW test user')->setPassword('unused')->verifyEmail()->setPermissions($manager ? [CmsPermission::VIDEO] : []);
        $this->em($client)->persist($user); $this->em($client)->flush(); return $user;
    }
    private function video(KernelBrowser $client): Video
    {
        $video = (new Video())->setTitle('VW test video')->setSlug('vw-test-'.bin2hex(random_bytes(6)))->setDescription('VW test description')->setSourceType(Video::SOURCE_YOUTUBE)->setSourceUrl('https://youtu.be/abcdef12345')->setPublishedAt(new \DateTimeImmutable('-1 minute'));
        $this->em($client)->persist($video); $this->em($client)->flush(); return $video;
    }
    private function source(KernelBrowser $client, ?Video $video, ?CreatorProfile $creator = null): VideoSource
    {
        $em = $this->em($client);
        $source = (new VideoSource())->setVideo($video === null ? null : $em->getReference(Video::class, $video->getId()))->setCreator($creator === null ? null : $em->getReference(CreatorProfile::class, $creator->getId())); $source->configure('VW test HLS', 'hls', 'https://cdn.example.test/live/index.m3u8', 0, true, true);
        $this->em($client)->persist($source); $this->em($client)->flush(); return $source;
    }
    /** @return array<string,mixed> */
    private function sourceFields(?int $video, string $token): array { return ['_token' => $token, 'version' => '1', 'label' => 'VW test source', 'provider' => 'hls', 'url' => 'https://cdn.example.test/live/index.m3u8', 'video_id' => (string) $video, 'creator_id' => '0', 'position' => '0', 'enabled' => '1', 'authorized' => '1', 'starts_at' => '']; }
    /** @return array<string,string> */
    private function ownedFields(Crawler $crawler): array { return ['_token' => $this->token($crawler), '_version' => (string) $crawler->filter('input[name="_version"]')->first()->attr('value')]; }
    private function token(Crawler $crawler): string { return (string) $crawler->filter('input[name="_token"]')->first()->attr('value'); }
    private function em(KernelBrowser $client): EntityManagerInterface { return $client->getContainer()->get(EntityManagerInterface::class); }
}

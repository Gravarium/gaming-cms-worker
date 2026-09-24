<?php

declare(strict_types=1);

namespace App\Tests\Video\Discovery;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoDiscovery\CreatorProfile;
use App\Entity\VideoDiscovery\VideoClip;
use App\Entity\VideoDiscovery\VideoDiscoveryProfile;
use App\Entity\VideoDiscovery\VideoFavorite;
use App\Entity\VideoDiscovery\VideoHistoryEntry;
use App\Entity\VideoDiscovery\VideoHistoryPreference;
use App\Entity\VideoDiscovery\VideoLiveStream;
use App\Entity\VideoDiscovery\VideoWatchlist;
use App\Entity\VideoDiscovery\VideoWatchlistItem;
use App\Repository\VideoDiscovery\VideoDiscoveryProfileRepository;
use App\Video\Discovery\LiveEmbedPolicy;
use App\Video\Discovery\VideoVisibilityPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class VideoDiscoveryInteractionTest extends WebTestCase
{
    public function testRepositorySearchFailsClosedForRestrictedProfiles(): void
    {
        $client = static::createClient();
        $this->setVideoModule($client, true);
        $token = 'privacy-'.bin2hex(random_bytes(4));
        $owner = $this->user($client, 'owner-'.$token);
        $member = $this->user($client, 'member-'.$token);
        $creator = (new CreatorProfile('Private creator', 'creator-'.$token))->setOwner($owner);

        $public = new VideoDiscoveryProfile($this->video($client, $token.'-public', true));
        $memberOnly = (new VideoDiscoveryProfile($this->video($client, $token.'-member', true)))
            ->setVisibility(VideoDiscoveryProfile::VISIBILITY_MEMBER);
        $private = (new VideoDiscoveryProfile($this->video($client, $token.'-private', true)))
            ->setCreator($creator)
            ->setVisibility(VideoDiscoveryProfile::VISIBILITY_PRIVATE);

        $this->em($client)->persist($creator);
        foreach ([$public, $memberOnly, $private] as $profile) {
            $this->em($client)->persist($profile);
        }
        $this->em($client)->flush();

        $repository = $client->getContainer()->get(VideoDiscoveryProfileRepository::class);

        self::assertCount(1, $repository->searchPublished($token, null, 50, null));
        self::assertCount(2, $repository->searchPublished($token, null, 50, $member));
        self::assertCount(3, $repository->searchPublished($token, null, 50, $owner));
    }

    public function testHistoryRecordingRequiresExplicitOptInAndCsrf(): void
    {
        $client = static::createClient();
        $this->setVideoModule($client, true);
        $user = $this->user($client, 'history');
        $video = $this->video($client, 'history', true);
        $profile = new VideoDiscoveryProfile($video);
        $this->em($client)->persist($profile);
        $this->em($client)->flush();
        $client->loginUser($user);

        $client->request('POST', '/account/video-discovery/history/'.$video->getId(), [
            '_token' => $this->csrf($client, 'video-history-record-'.$video->getId()),
            'position_seconds' => '12',
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertCount(0, $this->em($client)->getRepository(VideoHistoryEntry::class)->findBy(['user' => $user]));

        $preference = (new VideoHistoryPreference($user))->setEnabled(true);
        $this->em($client)->persist($preference);
        $this->em($client)->flush();

        $client->request('POST', '/account/video-discovery/history/'.$video->getId(), [
            '_token' => 'invalid',
            'position_seconds' => '12',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->em($client)->getRepository(VideoHistoryEntry::class)->findBy(['user' => $user]));

        $client->request('POST', '/account/video-discovery/history/'.$video->getId(), [
            '_token' => $this->csrf($client, 'video-history-record-'.$video->getId()),
            'position_seconds' => '12',
        ]);
        self::assertResponseRedirects('/video-discovery/videos/'.$profile->getId());

        $entries = $this->em($client)->getRepository(VideoHistoryEntry::class)->findBy(['user' => $user]);
        self::assertCount(1, $entries);
        self::assertSame(12, $entries[0]->getPositionSeconds());
    }

    public function testFavoriteToggleRejectsBadCsrfBeforeWriting(): void
    {
        $client = static::createClient();
        $this->setVideoModule($client, true);
        $user = $this->user($client, 'favorite');
        $video = $this->video($client, 'favorite', true);
        $this->em($client)->persist(new VideoDiscoveryProfile($video));
        $this->em($client)->flush();
        $client->loginUser($user);

        $client->request('POST', '/account/video-discovery/favorite/'.$video->getId(), ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em($client)->getRepository(VideoFavorite::class)->findOneBy(['user' => $user, 'video' => $video]));

        $client->request('POST', '/account/video-discovery/favorite/'.$video->getId(), [
            '_token' => $this->csrf($client, 'video-favorite-'.$video->getId()),
        ]);
        self::assertResponseRedirects();
        self::assertInstanceOf(
            VideoFavorite::class,
            $this->em($client)->getRepository(VideoFavorite::class)->findOneBy(['user' => $user, 'video' => $video]),
        );
    }

    public function testForeignWatchlistIsHiddenFromMutation(): void
    {
        $client = static::createClient();
        $this->setVideoModule($client, true);
        $owner = $this->user($client, 'watchlist-owner');
        $other = $this->user($client, 'watchlist-other');
        $video = $this->video($client, 'watchlist', true);
        $watchlist = new VideoWatchlist($owner, 'Owner list '.bin2hex(random_bytes(3)));
        $this->em($client)->persist($watchlist);
        $this->em($client)->flush();
        self::assertNotNull($watchlist->getId());

        $client->loginUser($other);
        $client->request('POST', '/account/video-discovery/watchlists/'.$watchlist->getId().'/videos/'.$video->getId(), [
            '_token' => $this->csrf($client, 'video-watchlist-add-'.$watchlist->getId().'-'.$video->getId()),
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->em($client)->getRepository(VideoWatchlistItem::class)->findOneBy([
            'watchlist' => $watchlist,
            'video' => $video,
        ]));
    }

    public function testLivePolicyDeniesMalformedParentAndNoConsent(): void
    {
        $stream = new VideoLiveStream('Live', 'twitch', 'https://twitch.tv/example_channel');
        $policy = new LiveEmbedPolicy();

        self::assertNull($policy->resolve($stream, 'community.example.test', false));
        self::assertNull($policy->resolve($stream, 'bad host/value', true));
        self::assertSame(
            ['provider' => 'twitch', 'url' => 'https://player.twitch.tv/?channel=example_channel&parent=community.example.test'],
            $policy->resolve($stream, 'community.example.test:443', true),
        );
    }

    public function testClipVisibilityAlsoRequiresPublishedVideo(): void
    {
        $client = static::createClient();
        $user = $this->user($client, 'clip-owner');
        $video = $this->video($client, 'draft-clip', false);
        $clip = new VideoClip($video, $user, 'Draft clip', 'draft-clip-'.bin2hex(random_bytes(3)), 0, 30);

        self::assertFalse((new VideoVisibilityPolicy())->canViewClip($clip, $user));
    }

    public function testPublicLivePageDoesNotLoadProviderBeforeConsent(): void
    {
        $client = static::createClient();
        $this->setVideoModule($client, true);
        $stream = new VideoLiveStream('Consent live', 'youtube', 'https://youtu.be/abcdef123');
        $this->em($client)->persist($stream);
        $this->em($client)->flush();
        self::assertNotNull($stream->getId());

        $crawler = $client->request('GET', '/video-discovery/live/'.$stream->getId());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('youtube-nocookie.com', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('erst nach deiner Zustimmung', $crawler->filter('body')->text());

        $client->request('POST', '/video-discovery/live/'.$stream->getId().'/consent', [
            '_token' => $this->csrf($client, 'video-live-consent-'.$stream->getId()),
        ]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('https://www.youtube-nocookie.com/embed/abcdef123', (string) $client->getResponse()->getContent());
    }

    private function user(KernelBrowser $client, string $suffix): User
    {
        $user = (new User())
            ->setEmail('video-discovery-interaction-'.$suffix.'-'.bin2hex(random_bytes(4)).'@example.test')
            ->setDisplayName('Video discovery '.$suffix)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function video(KernelBrowser $client, string $suffix, bool $published): Video
    {
        $video = (new Video())
            ->setTitle('Discovery '.$suffix)
            ->setSlug('discovery-'.$suffix.'-'.bin2hex(random_bytes(4)))
            ->setDescription('Discovery '.$suffix)
            ->setSourceType(Video::SOURCE_YOUTUBE)
            ->setSourceUrl('https://www.youtube.com/watch?v=abcdef123');
        if ($published) {
            $video->setPublishedAt(new \DateTimeImmutable('-1 minute'));
        }
        $this->em($client)->persist($video);
        $this->em($client)->flush();

        return $video;
    }

    private function setVideoModule(KernelBrowser $client, bool $enabled): void
    {
        $state = $this->em($client)->find(CmsModuleState::class, 'video');
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('video')->updateVersion('test');
            $this->em($client)->persist($state);
        }
        $state->setEnabled($enabled);
        $this->em($client)->flush();
    }

    private function csrf(KernelBrowser $client, string $id): string
    {
        $manager = $client->getContainer()->get(CsrfTokenManagerInterface::class);

        return $manager->getToken($id)->getValue();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}

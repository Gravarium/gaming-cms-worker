<?php

declare(strict_types=1);

namespace App\Tests\Video\Discovery;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoDiscovery\CreatorProfile;
use App\Entity\VideoDiscovery\VideoClip;
use App\Entity\VideoDiscovery\VideoDiscoveryProfile;
use App\Entity\VideoDiscovery\VideoHistoryPreference;
use App\Entity\VideoDiscovery\VideoLiveStream;
use App\Entity\VideoDiscovery\VideoTag;
use App\Entity\VideoDiscovery\VideoWatchlist;
use App\Repository\VideoDiscovery\VideoDiscoveryProfileRepository;
use App\Video\Discovery\LiveEmbedPolicy;
use App\Video\Discovery\VideoVisibilityPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoDiscoverySecurityTest extends WebTestCase
{
    public function testHistoryPreferenceRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('POST', '/account/video-discovery/history-preference', [
            '_token' => 'invalid',
            'enabled' => '1',
        ]);

        self::assertResponseRedirects('/login');
    }

    public function testHistoryPreferenceRejectsMissingCsrfWithoutPersisting(): void
    {
        $client = static::createClient();
        $this->setVideoModule($client, true);
        $user = $this->user($client, 'csrf');
        $client->loginUser($user);

        $client->request('POST', '/account/video-discovery/history-preference', ['enabled' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->preference($client, $user));
    }

    public function testHistoryPreferenceRejectsInvalidValue(): void
    {
        $client = static::createClient();
        $this->setVideoModule($client, true);
        $user = $this->user($client, 'invalid');
        $client->loginUser($user);

        $client->request('POST', '/account/video-discovery/history-preference', [
            '_token' => $this->csrf($client),
            'enabled' => 'yes',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->preference($client, $user));
    }

    public function testHistoryIsOptInAndCanBeDisabledAgain(): void
    {
        $client = static::createClient();
        $this->setVideoModule($client, true);
        $user = $this->user($client, 'optin');
        $client->loginUser($user);

        self::assertNull($this->preference($client, $user));

        $client->request('POST', '/account/video-discovery/history-preference', [
            '_token' => $this->csrf($client),
            'enabled' => '1',
        ]);
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"enabled":true}', (string) $client->getResponse()->getContent());

        $this->em($client)->clear();
        $storedUser = $this->em($client)->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $storedUser);
        $enabled = $this->preference($client, $storedUser);
        self::assertInstanceOf(VideoHistoryPreference::class, $enabled);
        self::assertTrue($enabled->isEnabled());

        $client->request('POST', '/account/video-discovery/history-preference', [
            '_token' => $this->csrf($client),
            'enabled' => '0',
        ]);
        self::assertResponseIsSuccessful();

        $this->em($client)->clear();
        $storedUser = $this->em($client)->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $storedUser);
        $disabled = $this->preference($client, $storedUser);
        self::assertInstanceOf(VideoHistoryPreference::class, $disabled);
        self::assertFalse($disabled->isEnabled());
    }

    public function testDisabledVideoModuleBlocksPreferenceMutation(): void
    {
        $client = static::createClient();
        $this->setVideoModule($client, true);
        $user = $this->user($client, 'disabled');
        $client->loginUser($user);
        $token = $this->csrf($client);
        $this->setVideoModule($client, false);

        try {
            $client->request('POST', '/account/video-discovery/history-preference', [
                '_token' => $token,
                'enabled' => '1',
            ]);

            self::assertResponseStatusCodeSame(404);
            self::assertNull($this->preference($client, $user));
        } finally {
            $this->setVideoModule($client, true);
        }
    }

    public function testPrivateAndMemberVisibilityFailClosed(): void
    {
        $client = static::createClient();
        $owner = $this->user($client, 'owner');
        $other = $this->user($client, 'other');
        $video = $this->video($client, 'visibility', true);
        $creator = (new CreatorProfile('Creator', 'creator-'.bin2hex(random_bytes(4))))->setOwner($owner);
        $profile = (new VideoDiscoveryProfile($video))
            ->setCreator($creator)
            ->setVisibility(VideoDiscoveryProfile::VISIBILITY_PRIVATE);
        $watchlist = new VideoWatchlist($owner, 'Private list '.bin2hex(random_bytes(3)));
        $clip = (new VideoClip($video, $owner, 'Private clip', 'private-clip-'.bin2hex(random_bytes(4)), 10, 40))
            ->setVisibility('private');

        foreach ([$creator, $profile, $watchlist, $clip] as $entity) {
            $this->em($client)->persist($entity);
        }
        $this->em($client)->flush();

        $policy = new VideoVisibilityPolicy();
        self::assertTrue($policy->canViewProfile($profile, $owner));
        self::assertFalse($policy->canViewProfile($profile, $other));
        self::assertFalse($policy->canViewProfile($profile, null));
        self::assertTrue($policy->canViewWatchlist($watchlist, $owner));
        self::assertFalse($policy->canViewWatchlist($watchlist, $other));
        self::assertTrue($policy->canViewClip($clip, $owner));
        self::assertFalse($policy->canViewClip($clip, $other));

        $profile->setVisibility(VideoDiscoveryProfile::VISIBILITY_MEMBER);
        self::assertTrue($policy->canViewProfile($profile, $other));
        $other->lockUntil(new \DateTimeImmutable('+1 hour'), 'test lock');
        self::assertFalse($policy->canViewProfile($profile, $other));

        $video->setEnabled(false);
        self::assertFalse($policy->canViewProfile($profile, $owner));
    }

    public function testLiveEmbedsRequireConsentAndRejectUnsafeProviderUrls(): void
    {
        $policy = new LiveEmbedPolicy();

        $youtube = new VideoLiveStream('Live', 'youtube', 'https://youtu.be/abcdef123');
        self::assertNull($policy->resolve($youtube, 'community.example.test', false));
        self::assertSame(
            ['provider' => 'youtube', 'url' => 'https://www.youtube-nocookie.com/embed/abcdef123'],
            $policy->resolve($youtube, 'community.example.test', true),
        );

        $credentialed = new VideoLiveStream('Credentialed', 'youtube', 'https://user:pass@youtu.be/abcdef123');
        self::assertNull($policy->resolve($credentialed, 'community.example.test', true));

        $http = new VideoLiveStream('HTTP', 'vimeo', 'http://vimeo.com/123456');
        self::assertNull($policy->resolve($http, 'community.example.test', true));

        $lookalike = new VideoLiveStream('Lookalike', 'youtube', 'https://youtube.com.evil.test/embed/abcdef123');
        self::assertNull($policy->resolve($lookalike, 'community.example.test', true));

        $twitch = new VideoLiveStream('Twitch', 'twitch', 'https://twitch.tv/example_channel');
        self::assertSame(
            ['provider' => 'twitch', 'url' => 'https://player.twitch.tv/?channel=example_channel&parent=community.example.test'],
            $policy->resolve($twitch, 'community.example.test:443', true),
        );
    }

    public function testSearchReturnsOnlyPublishedDiscoverableProfilesMatchingTagAndQuery(): void
    {
        $client = static::createClient();
        $tag = new VideoTag('Strategy', 'strategy-'.bin2hex(random_bytes(3)));
        $visibleVideo = $this->video($client, 'alpha-strategy', true);
        $hiddenVideo = $this->video($client, 'alpha-hidden', true);
        $draftVideo = $this->video($client, 'alpha-draft', false);

        $visible = (new VideoDiscoveryProfile($visibleVideo))->addTag($tag);
        $hidden = (new VideoDiscoveryProfile($hiddenVideo))->addTag($tag)->setDiscoverable(false);
        $draft = (new VideoDiscoveryProfile($draftVideo))->addTag($tag);

        $this->em($client)->persist($tag);
        foreach ([$visible, $hidden, $draft] as $profile) {
            $this->em($client)->persist($profile);
        }
        $this->em($client)->flush();

        $repository = $client->getContainer()->get(VideoDiscoveryProfileRepository::class);
        $results = $repository->searchPublished('alpha', $tag);

        self::assertCount(1, $results);
        self::assertSame($visibleVideo->getId(), $results[0]->getVideo()->getId());
    }

    public function testClipRejectsExcessiveDuration(): void
    {
        $client = static::createClient();
        $user = $this->user($client, 'clip');
        $video = $this->video($client, 'clip-duration', true);

        $this->expectException(\InvalidArgumentException::class);
        new VideoClip($video, $user, 'Too long', 'too-long-'.bin2hex(random_bytes(3)), 0, 601);
    }

    private function user(KernelBrowser $client, string $suffix): User
    {
        $user = (new User())
            ->setEmail('video-discovery-'.$suffix.'-'.bin2hex(random_bytes(4)).'@example.test')
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
            ->setTitle('Alpha '.$suffix)
            ->setSlug('video-'.$suffix.'-'.bin2hex(random_bytes(4)))
            ->setDescription('Alpha discovery description '.$suffix)
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

    private function preference(KernelBrowser $client, User $user): ?VideoHistoryPreference
    {
        $preference = $this->em($client)->getRepository(VideoHistoryPreference::class)->findOneBy(['user' => $user]);

        return $preference instanceof VideoHistoryPreference ? $preference : null;
    }

    private function csrf(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/account/video-discovery/history-preference');
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('input[name="_token"]')->attr('value');
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}

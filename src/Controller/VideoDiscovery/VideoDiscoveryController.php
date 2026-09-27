<?php

declare(strict_types=1);

namespace App\Controller\VideoDiscovery;

use App\Entity\User;
use App\Entity\VideoDiscovery\CreatorProfile;
use App\Entity\VideoDiscovery\VideoClip;
use App\Entity\VideoDiscovery\VideoDiscoveryProfile;
use App\Entity\VideoDiscovery\VideoHistoryPreference;
use App\Entity\VideoDiscovery\VideoLiveStream;
use App\Entity\VideoDiscovery\VideoTag;
use App\Entity\VideoDiscovery\VideoTimestampComment;
use App\Entity\VideoDiscovery\VideoWatchlist;
use App\Entity\VideoDiscovery\VideoWatchlistItem;
use App\Repository\VideoDiscovery\VideoDiscoveryProfileRepository;
use App\Video\Discovery\LiveEmbedPolicy;
use App\Video\Discovery\VideoModuleAvailability;
use App\Video\Discovery\VideoVisibilityPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/video-discovery')]
final class VideoDiscoveryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoDiscoveryProfileRepository $profiles,
        private readonly VideoModuleAvailability $availability,
        private readonly VideoVisibilityPolicy $visibility,
        private readonly LiveEmbedPolicy $liveEmbedPolicy,
    ) {
    }

    #[Route('', name: 'app_video_discovery_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireEnabled();

        $viewer = $this->viewer();
        $tag = null;
        $tagSlug = $request->query->getString('tag');
        if ($tagSlug !== '') {
            $tag = $this->entityManager->getRepository(VideoTag::class)->findOneBy(['slug' => $tagSlug]);
            if (!$tag instanceof VideoTag) {
                throw $this->createNotFoundException();
            }
        }

        $rows = [];
        foreach ($this->profiles->searchPublished($request->query->getString('q'), $tag, 50, $viewer) as $profile) {
            $creator = $profile->getCreator();
            $rows[] = [
                'profile' => $profile,
                'creator' => $creator instanceof CreatorProfile && $this->visibility->canViewCreator($creator, $viewer) ? $creator : null,
            ];
        }

        $watchlists = [];
        if ($viewer instanceof User && $viewer->isActive() && !$viewer->isLocked()) {
            $watchlists = $this->entityManager->getRepository(VideoWatchlist::class)->findBy(['user' => $viewer], ['createdAt' => 'DESC']);
        }

        return $this->render('video_discovery/index.html.twig', [
            'rows' => $rows,
            'tags' => $this->entityManager->getRepository(VideoTag::class)->findBy([], ['name' => 'ASC']),
            'activeTag' => $tag,
            'query' => $request->query->getString('q'),
            'viewerWatchlists' => $watchlists,
        ]);
    }

    #[Route('/videos/{id}', name: 'app_video_discovery_video', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function video(int $id): Response
    {
        $this->requireEnabled();
        $viewer = $this->viewer();

        $profile = $this->entityManager->find(VideoDiscoveryProfile::class, $id);
        if (!$profile instanceof VideoDiscoveryProfile || !$this->visibility->canViewProfile($profile, $viewer)) {
            throw $this->createNotFoundException();
        }

        $video = $profile->getVideo();
        $comments = array_values(array_filter(
            $this->entityManager->getRepository(VideoTimestampComment::class)->findBy(['video' => $video], ['createdAt' => 'ASC']),
            fn (VideoTimestampComment $comment): bool => $this->visibility->canViewTimestampComment($comment, $viewer),
        ));
        $clips = array_values(array_filter(
            $this->entityManager->getRepository(VideoClip::class)->findBy(['video' => $video], ['createdAt' => 'DESC']),
            fn (VideoClip $clip): bool => $this->visibility->canViewClip($clip, $viewer),
        ));

        $watchlists = [];
        $historyEnabled = false;
        if ($viewer instanceof User && $viewer->isActive() && !$viewer->isLocked()) {
            $watchlists = $this->entityManager->getRepository(VideoWatchlist::class)->findBy(['user' => $viewer], ['createdAt' => 'DESC']);
            $preference = $this->entityManager->getRepository(VideoHistoryPreference::class)->findOneBy(['user' => $viewer]);
            $historyEnabled = $preference instanceof VideoHistoryPreference && $preference->isEnabled();
        }

        $creator = $profile->getCreator();

        return $this->render('video_discovery/video.html.twig', [
            'profile' => $profile,
            'video' => $video,
            'creator' => $creator instanceof CreatorProfile && $this->visibility->canViewCreator($creator, $viewer) ? $creator : null,
            'comments' => $comments,
            'clips' => $clips,
            'viewerWatchlists' => $watchlists,
            'historyEnabled' => $historyEnabled,
        ]);
    }

    #[Route('/creators/{slug}', name: 'app_video_discovery_creator', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function creator(string $slug): Response
    {
        $this->requireEnabled();
        $viewer = $this->viewer();

        $creator = $this->entityManager->getRepository(CreatorProfile::class)->findOneBy(['slug' => $slug]);
        if (!$creator instanceof CreatorProfile || !$this->visibility->canViewCreator($creator, $viewer)) {
            throw $this->createNotFoundException();
        }

        $profiles = array_values(array_filter(
            $this->entityManager->getRepository(VideoDiscoveryProfile::class)->findBy(['creator' => $creator]),
            fn (VideoDiscoveryProfile $profile): bool => $this->visibility->canViewProfile($profile, $viewer),
        ));
        $streams = array_values(array_filter(
            $this->entityManager->getRepository(VideoLiveStream::class)->findBy(['creator' => $creator], ['startsAt' => 'DESC']),
            fn (VideoLiveStream $stream): bool => $this->visibility->canViewLiveStream($stream, $viewer),
        ));

        return $this->render('video_discovery/creator.html.twig', [
            'creator' => $creator,
            'profiles' => $profiles,
            'streams' => $streams,
        ]);
    }

    #[Route('/watchlists/{id}', name: 'app_video_discovery_watchlist', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function watchlist(int $id): Response
    {
        $this->requireEnabled();
        $viewer = $this->viewer();

        $watchlist = $this->entityManager->find(VideoWatchlist::class, $id);
        if (!$watchlist instanceof VideoWatchlist || !$this->visibility->canViewWatchlist($watchlist, $viewer)) {
            throw $this->createNotFoundException();
        }

        $items = array_values(array_filter(
            $this->entityManager->getRepository(VideoWatchlistItem::class)->findBy(['watchlist' => $watchlist], ['createdAt' => 'ASC']),
            static fn (VideoWatchlistItem $item): bool => $item->getVideo()->isPublished(),
        ));

        return $this->render('video_discovery/watchlist.html.twig', [
            'watchlist' => $watchlist,
            'items' => $items,
        ]);
    }

    #[Route('/clips/{slug}', name: 'app_video_discovery_clip', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function clip(string $slug): Response
    {
        $this->requireEnabled();
        $viewer = $this->viewer();

        $clip = $this->entityManager->getRepository(VideoClip::class)->findOneBy(['slug' => $slug]);
        if (!$clip instanceof VideoClip || !$this->visibility->canViewClip($clip, $viewer)) {
            throw $this->createNotFoundException();
        }

        return $this->render('video_discovery/clip.html.twig', ['clip' => $clip]);
    }

    #[Route('/live/{id}', name: 'app_video_discovery_live', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function live(int $id): Response
    {
        return $this->renderLive($id, null);
    }

    #[Route('/live/{id}/consent', name: 'app_video_discovery_live_consent', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function liveConsent(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('video-live-consent-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        return $this->renderLive($id, $request);
    }

    private function renderLive(int $id, ?Request $consentRequest): Response
    {
        $this->requireEnabled();
        $viewer = $this->viewer();
        $stream = $this->entityManager->find(VideoLiveStream::class, $id);

        if (!$stream instanceof VideoLiveStream || !$this->visibility->canViewLiveStream($stream, $viewer)) {
            throw $this->createNotFoundException();
        }

        $player = $consentRequest instanceof Request
            ? $this->liveEmbedPolicy->resolve($stream, $consentRequest->getHost(), true)
            : null;

        return $this->render('video_discovery/live.html.twig', [
            'stream' => $stream,
            'player' => $player,
            'consented' => $consentRequest instanceof Request,
        ]);
    }

    private function requireEnabled(): void
    {
        if (!$this->availability->enabled()) {
            throw $this->createNotFoundException();
        }
    }

    private function viewer(): ?User
    {
        $viewer = $this->getUser();

        return $viewer instanceof User ? $viewer : null;
    }
}

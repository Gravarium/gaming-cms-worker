<?php

declare(strict_types=1);

namespace App\Controller\VideoDiscovery;

use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoDiscovery\VideoClip;
use App\Entity\VideoDiscovery\VideoDiscoveryProfile;
use App\Entity\VideoDiscovery\VideoFavorite;
use App\Entity\VideoDiscovery\VideoHistoryEntry;
use App\Entity\VideoDiscovery\VideoHistoryPreference;
use App\Entity\VideoDiscovery\VideoTimestampComment;
use App\Entity\VideoDiscovery\VideoWatchlist;
use App\Entity\VideoDiscovery\VideoWatchlistItem;
use App\Video\Discovery\VideoModuleAvailability;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/account/video-discovery')]
#[IsGranted('ROLE_USER')]
final class VideoDiscoveryInteractionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VideoModuleAvailability $availability,
        private readonly SluggerInterface $slugger,
    ) {
    }

    #[Route('/library', name: 'app_video_discovery_library', methods: ['GET'])]
    public function library(): Response
    {
        $this->requireEnabled();
        $user = $this->requireUser();

        $preference = $this->entityManager->getRepository(VideoHistoryPreference::class)->findOneBy(['user' => $user]);
        $historyEnabled = $preference instanceof VideoHistoryPreference && $preference->isEnabled();

        $favorites = array_values(array_filter(
            $this->entityManager->getRepository(VideoFavorite::class)->findBy(['user' => $user], ['createdAt' => 'DESC']),
            static fn (VideoFavorite $favorite): bool => $favorite->getVideo()->isPublished(),
        ));
        $watchlists = $this->entityManager->getRepository(VideoWatchlist::class)->findBy(['user' => $user], ['createdAt' => 'DESC']);
        $history = $historyEnabled
            ? array_values(array_filter(
                $this->entityManager->getRepository(VideoHistoryEntry::class)->findBy(['user' => $user], ['watchedAt' => 'DESC'], 50),
                static fn (VideoHistoryEntry $entry): bool => $entry->getVideo()->isPublished(),
            ))
            : [];

        return $this->render('video_discovery/library.html.twig', [
            'favorites' => $favorites,
            'watchlists' => $watchlists,
            'history' => $history,
            'historyEnabled' => $historyEnabled,
        ]);
    }

    #[Route('/favorite/{videoId}', name: 'app_video_discovery_favorite_toggle', requirements: ['videoId' => '\\d+'], methods: ['POST'])]
    public function favorite(int $videoId, Request $request): Response
    {
        $this->requireEnabled();
        $user = $this->requireUser();
        $video = $this->publishedVideo($videoId);
        $this->requireCsrf($request, 'video-favorite-'.$videoId);

        $repository = $this->entityManager->getRepository(VideoFavorite::class);
        $favorite = $repository->findOneBy(['user' => $user, 'video' => $video]);
        if ($favorite instanceof VideoFavorite) {
            $this->entityManager->remove($favorite);
        } else {
            $this->entityManager->persist(new VideoFavorite($user, $video));
        }
        $this->entityManager->flush();

        return $this->redirectForVideo($video);
    }

    #[Route('/watchlists', name: 'app_video_discovery_watchlist_create', methods: ['POST'])]
    public function createWatchlist(Request $request): Response
    {
        $this->requireEnabled();
        $user = $this->requireUser();
        $this->requireCsrf($request, 'video-watchlist-create');

        $public = $request->request->getString('public', '0');
        if (!in_array($public, ['0', '1'], true)) {
            throw new UnprocessableEntityHttpException('Watchlist visibility must be 0 or 1.');
        }

        try {
            $watchlist = new VideoWatchlist($user, $request->request->getString('name'));
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        if ($this->entityManager->getRepository(VideoWatchlist::class)->findOneBy([
            'user' => $user,
            'name' => $watchlist->getName(),
        ]) instanceof VideoWatchlist) {
            throw new ConflictHttpException('A watchlist with this name already exists.');
        }

        $watchlist->setPublic($public === '1');
        $this->entityManager->persist($watchlist);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_video_discovery_library');
    }

    #[Route('/watchlists/{watchlistId}/videos/{videoId}', name: 'app_video_discovery_watchlist_add', requirements: ['watchlistId' => '\\d+', 'videoId' => '\\d+'], methods: ['POST'])]
    public function addToWatchlist(int $watchlistId, int $videoId, Request $request): Response
    {
        $this->requireEnabled();
        $user = $this->requireUser();
        $watchlist = $this->ownedWatchlist($watchlistId, $user);
        $video = $this->publishedVideo($videoId);
        $this->requireCsrf($request, 'video-watchlist-add-'.$watchlistId.'-'.$videoId);

        $repository = $this->entityManager->getRepository(VideoWatchlistItem::class);
        if (!$repository->findOneBy(['watchlist' => $watchlist, 'video' => $video]) instanceof VideoWatchlistItem) {
            $this->entityManager->persist(new VideoWatchlistItem($watchlist, $video));
            $this->entityManager->flush();
        }

        return $this->redirectForVideo($video);
    }

    #[Route('/history/{videoId}', name: 'app_video_discovery_history_record', requirements: ['videoId' => '\\d+'], methods: ['POST'])]
    public function recordHistory(int $videoId, Request $request): Response
    {
        $this->requireEnabled();
        $user = $this->requireUser();
        $video = $this->publishedVideo($videoId);
        $this->requireCsrf($request, 'video-history-record-'.$videoId);

        $preference = $this->entityManager->getRepository(VideoHistoryPreference::class)->findOneBy(['user' => $user]);
        if (!$preference instanceof VideoHistoryPreference || !$preference->isEnabled()) {
            throw new ConflictHttpException('Watch history is disabled.');
        }

        $position = $this->nonNegativeInt($request, 'position_seconds', 86400, true);
        $this->entityManager->persist(new VideoHistoryEntry($user, $video, $position));
        $this->entityManager->flush();

        return $this->redirectForVideo($video);
    }

    #[Route('/comments/{videoId}', name: 'app_video_discovery_comment_create', requirements: ['videoId' => '\\d+'], methods: ['POST'])]
    public function comment(int $videoId, Request $request): Response
    {
        $this->requireEnabled();
        $user = $this->requireUser();
        $video = $this->publishedVideo($videoId);
        $this->requireCsrf($request, 'video-comment-'.$videoId);

        $timestamp = $this->nonNegativeInt($request, 'timestamp_seconds', 86400);
        try {
            $comment = new VideoTimestampComment($user, $video, $timestamp, $request->request->getString('body'));
            $comment->setVisibility($request->request->getString('visibility', 'public'));
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        return $this->redirectForVideo($video);
    }

    #[Route('/clips/{videoId}', name: 'app_video_discovery_clip_create', requirements: ['videoId' => '\\d+'], methods: ['POST'])]
    public function clip(int $videoId, Request $request): Response
    {
        $this->requireEnabled();
        $user = $this->requireUser();
        $video = $this->publishedVideo($videoId);
        $this->requireCsrf($request, 'video-clip-'.$videoId);

        $title = $request->request->getString('title');
        $start = $this->nonNegativeInt($request, 'start_seconds', 86400);
        $end = $this->nonNegativeInt($request, 'end_seconds', 86400);
        $slug = $this->uniqueClipSlug($title);

        try {
            $clip = new VideoClip($video, $user, $title, $slug, $start, $end);
            $clip->setVisibility($request->request->getString('visibility', 'public'));
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        $this->entityManager->persist($clip);
        $this->entityManager->flush();

        return $this->redirectToRoute('app_video_discovery_clip', ['slug' => $clip->getSlug()]);
    }

    private function requireEnabled(): void
    {
        if (!$this->availability->enabled()) {
            throw $this->createNotFoundException();
        }
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function publishedVideo(int $id): Video
    {
        $video = $this->entityManager->find(Video::class, $id);
        if (!$video instanceof Video || !$video->isPublished()) {
            throw $this->createNotFoundException();
        }

        return $video;
    }

    private function ownedWatchlist(int $id, User $user): VideoWatchlist
    {
        $watchlist = $this->entityManager->find(VideoWatchlist::class, $id);
        if (!$watchlist instanceof VideoWatchlist || $watchlist->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        return $watchlist;
    }

    private function requireCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function nonNegativeInt(Request $request, string $field, int $max, bool $optional = false): int
    {
        $raw = $request->request->getString($field, $optional ? '0' : '');
        if ($raw === '' || !ctype_digit($raw)) {
            throw new UnprocessableEntityHttpException($field.' must be a non-negative integer.');
        }

        $value = (int) $raw;
        if ($value > $max) {
            throw new UnprocessableEntityHttpException($field.' exceeds the allowed range.');
        }

        return $value;
    }

    private function uniqueClipSlug(string $title): string
    {
        $base = mb_strtolower(trim($this->slugger->slug($title)->toString()));
        $base = trim(mb_substr($base, 0, 160), '-');
        if ($base === '') {
            $base = 'clip';
        }

        $slug = $base;
        $number = 2;
        $repository = $this->entityManager->getRepository(VideoClip::class);
        while ($repository->findOneBy(['slug' => $slug]) instanceof VideoClip) {
            $suffix = '-'.$number++;
            $slug = mb_substr($base, 0, 180 - mb_strlen($suffix)).$suffix;
        }

        return $slug;
    }

    private function redirectForVideo(Video $video): Response
    {
        $profile = $this->entityManager->getRepository(VideoDiscoveryProfile::class)->findOneBy(['video' => $video]);
        if ($profile instanceof VideoDiscoveryProfile && $profile->getId() !== null) {
            return $this->redirectToRoute('app_video_discovery_video', ['id' => $profile->getId()]);
        }

        return $this->redirectToRoute('app_video_show', ['slug' => $video->getSlug()]);
    }
}

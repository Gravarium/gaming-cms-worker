<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\VideoPlaylist;
use App\Module\CmsModuleManager;
use App\Video\Library\VideoLibraryBrowser;
use App\Repository\VideoPlaylistRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/video-playlists')]
final class VideoPlaylistController extends AbstractController
{
    public function __construct(
        private readonly VideoPlaylistRepository $playlists,
        private readonly VideoLibraryBrowser $library,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('', name: 'app_video_playlist_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->assertAvailable();

        return $this->render('video_playlist/index.html.twig', [
            'playlists' => $this->playlists->findEnabled(),
        ]);
    }

    #[Route('/{slug}', name: 'app_video_playlist_show', methods: ['GET'])]
    public function show(string $slug, Request $request): Response
    {
        $this->assertAvailable();

        $playlist = $this->playlists->findEnabledBySlug($slug);
        if (!$playlist instanceof VideoPlaylist) {
            throw $this->createNotFoundException();
        }

        $page = $this->requestedPage($request);
        $listing = $this->library->page(null, $playlist, $page);
        $pages = max(1, (int) ceil($listing['total'] / VideoLibraryBrowser::PAGE_SIZE));
        if ($listing['total'] > 0 && $page > $pages) {
            throw $this->createNotFoundException();
        }
        if ($listing['total'] === 0) {
            $page = 1;
        }

        return $this->render('video_playlist/show.html.twig', [
            'playlist' => $playlist,
            'videos' => $listing['videos'],
            'total' => $listing['total'],
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    private function requestedPage(Request $request): int
    {
        $rawPage = $request->query->all()['page'] ?? null;
        if (!is_string($rawPage) && !is_int($rawPage)) {
            return 1;
        }

        $page = filter_var($rawPage, FILTER_VALIDATE_INT);
        if (!is_int($page)) {
            return 1;
        }

        return max(1, min(10_000, $page));
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('video')) {
            throw $this->createNotFoundException();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\VideoCategory;
use App\Repository\VideoCategoryRepository;
use App\Repository\VideoPlaylistRepository;
use App\Video\Library\VideoLibraryBrowser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicVideoLibraryController extends AbstractController
{
    public function __construct(
        private readonly VideoLibraryBrowser $library,
        private readonly VideoCategoryRepository $categories,
        private readonly VideoPlaylistRepository $playlists,
    ) {
    }

    #[Route('/videos/library', name: 'app_video_library', priority: 20, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $categorySlug = trim($request->query->getString('category'));
        $category = null;
        if ($categorySlug !== '') {
            $category = $this->categories->findOneBy(['slug' => $categorySlug, 'enabled' => true]);
            if (!$category instanceof VideoCategory) {
                throw $this->createNotFoundException();
            }
        }

        $playlistSlug = trim($request->query->getString('playlist'));
        $playlist = $playlistSlug === '' ? null : $this->playlists->findEnabledBySlug($playlistSlug);
        if ($playlistSlug !== '' && $playlist === null) {
            throw $this->createNotFoundException();
        }

        $page = max(1, min(10000, $request->query->getInt('page', 1)));
        $listing = $this->library->page($category, $playlist, $page);
        $pages = max(1, (int) ceil($listing['total'] / VideoLibraryBrowser::PAGE_SIZE));
        if ($listing['total'] > 0 && $page > $pages) {
            throw $this->createNotFoundException();
        }
        if ($listing['total'] === 0) {
            $page = 1;
        }

        return $this->render('index.html.twig', [
            'videos' => $listing['videos'],
            'categories' => $this->categories->findEnabled(),
            'playlists' => $this->playlists->findEnabled(),
            'activeCategory' => $category,
            'activePlaylist' => $playlist,
            'page' => $page,
            'pages' => $pages,
            'total' => $listing['total'],
            'pageSize' => VideoLibraryBrowser::PAGE_SIZE,
        ]);
    }
}

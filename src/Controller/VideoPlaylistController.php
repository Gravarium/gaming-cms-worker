<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\VideoPlaylist;
use App\Module\CmsModuleManager;
use App\Repository\VideoPlaylistRepository;
use App\Repository\VideoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/video-playlists')]
final class VideoPlaylistController extends AbstractController
{
    public function __construct(
        private readonly VideoPlaylistRepository $playlists,
        private readonly VideoRepository $videos,
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
    public function show(string $slug): Response
    {
        $this->assertAvailable();

        $playlist = $this->playlists->findEnabledBySlug($slug);
        if (!$playlist instanceof VideoPlaylist) {
            throw $this->createNotFoundException();
        }

        return $this->render('video_playlist/show.html.twig', [
            'playlist' => $playlist,
            'videos' => $this->videos->findPublished(null, $playlist),
        ]);
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('video')) {
            throw $this->createNotFoundException();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\VideoCategoryRepository;
use App\Repository\VideoPlaylistRepository;
use App\Repository\VideoRepository;
use App\Service\VideoEmbedResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VideoController extends AbstractController
{
    public function __construct(
        private readonly VideoRepository $videos,
        private readonly VideoCategoryRepository $categories,
        private readonly VideoPlaylistRepository $playlists,
        private readonly VideoEmbedResolver $embedResolver,
    ) {
    }

    #[Route('/videos', name: 'app_video_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $category = null;
        $playlist = null;

        $categorySlug = $request->query->getString('category');
        if ($categorySlug !== '') {
            $category = $this->categories->findOneBy(['slug' => $categorySlug, 'enabled' => true]);
            if ($category === null) { throw $this->createNotFoundException(); }
        }

        $playlistSlug = $request->query->getString('playlist');
        if ($playlistSlug !== '') {
            $playlist = $this->playlists->findEnabledBySlug($playlistSlug);
            if ($playlist === null) { throw $this->createNotFoundException(); }
        }

        return $this->render('video/index.html.twig', [
            'videos' => $this->videos->findPublished($category, $playlist),
            'categories' => $this->categories->findEnabled(),
            'playlists' => $this->playlists->findEnabled(),
            'activeCategory' => $category,
            'activePlaylist' => $playlist,
        ]);
    }

    #[Route('/videos/{slug}', name: 'app_video_show', methods: ['GET'])]
    public function show(string $slug, Request $request): Response
    {
        $video = $this->videos->findPublishedBySlug($slug);
        if ($video === null) { throw $this->createNotFoundException(); }

        return $this->render('video/show.html.twig', [
            'video' => $video,
            'player' => $this->embedResolver->resolve($video, $request->getHost()),
        ]);
    }

}

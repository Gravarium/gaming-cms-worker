<?php

declare(strict_types=1);

namespace App\Controller;

use App\GameGenreDirectory\PublicGameGenreDirectoryQuery;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PublicGameGenreDirectoryController extends AbstractController
{
    public function __construct(
        private readonly PublicGameGenreDirectoryQuery $genres,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/games/genres', name: 'app_gaming_game_genre_index', methods: ['GET'], priority: 100)]
    public function index(Request $request): Response
    {
        $this->assertAvailable();
        $page = $this->page($request);
        $total = $this->genres->countPublicGenres();
        $totalPages = max(1, (int) ceil($total / PublicGameGenreDirectoryQuery::GENRE_PAGE_SIZE));
        if ($page > $totalPages) {
            throw $this->createNotFoundException();
        }

        return $this->render('game_genre_directory/index.html.twig', [
            'genres' => $this->genres->findPublicGenres($page),
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    #[Route('/games/genres/{slug}', name: 'app_gaming_game_genre_show', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function show(string $slug, Request $request): Response
    {
        $this->assertAvailable();
        $page = $this->page($request);
        $genre = $this->genres->findPublicGenreBySlug($slug);
        if ($genre === null) {
            throw $this->createNotFoundException();
        }

        $total = $this->genres->countPublicGamesByGenre($slug);
        $totalPages = max(1, (int) ceil($total / PublicGameGenreDirectoryQuery::GAME_PAGE_SIZE));
        if ($page > $totalPages) {
            throw $this->createNotFoundException();
        }

        return $this->render('game_genre_directory/genre.html.twig', [
            'genre' => $genre,
            'entries' => $this->genres->findPublicGamesByGenre($slug, $page),
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }

    private function page(Request $request): int
    {
        $parameters = $request->query->all();
        $rawPage = $parameters['page'] ?? '1';
        if (!is_string($rawPage) || preg_match('/\A[1-9][0-9]{0,3}\z/', $rawPage) !== 1) {
            throw new BadRequestHttpException('The page must be a positive integer.');
        }

        $page = (int) $rawPage;
        if ($page > PublicGameGenreDirectoryQuery::MAX_PAGE) {
            throw new BadRequestHttpException('The requested page is outside the supported range.');
        }

        return $page;
    }
}

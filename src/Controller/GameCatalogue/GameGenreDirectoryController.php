<?php

declare(strict_types=1);

namespace App\Controller\GameCatalogue;

use App\GameGenreDirectory\PublicGameGenreDirectory;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/game-genres')]
final class GameGenreDirectoryController extends AbstractController
{
    public function __construct(
        private readonly PublicGameGenreDirectory $directory,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('', name: 'app_game_genre_directory', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->assertAvailable();
        $page = $this->page($request);
        $result = $this->directory->genres($page);

        return $this->render('game_genre_directory/index.html.twig', [
            'genres' => $result['items'],
            'hasMore' => $result['hasMore'],
            'page' => $page,
        ]);
    }

    #[Route('/{slug}', name: 'app_game_genre_directory_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function show(string $slug, Request $request): Response
    {
        $this->assertAvailable();
        if (strlen($slug) > 120) {
            throw $this->createNotFoundException();
        }
        $page = $this->page($request);
        $result = $this->directory->genre($slug, $page);
        if ($result === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('game_genre_directory/show.html.twig', [
            'genre' => $result['genre'],
            'entries' => $result['entries'],
            'hasMore' => $result['hasMore'],
            'page' => $page,
        ]);
    }

    private function page(Request $request): int
    {
        $page = $request->query->getString('page', '1');
        if (preg_match('/^[1-9][0-9]{0,2}$/D', $page) !== 1) {
            throw $this->createNotFoundException();
        }
        $number = (int) $page;
        if ($number > PublicGameGenreDirectory::MAX_PAGE) {
            throw $this->createNotFoundException();
        }

        return $number;
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }
}

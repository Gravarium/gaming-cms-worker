<?php

declare(strict_types=1);

namespace App\Controller\GameCatalogue;

use App\GamePublisherDirectory\PublicGamePublisherQuery;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/games/publishers')]
final class GamePublisherDirectoryController extends AbstractController
{
    public function __construct(
        private readonly PublicGamePublisherQuery $publishers,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('', name: 'app_game_publisher_directory', priority: 100, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->assertAvailable();
        $page = $this->page($request);
        $result = $this->publishers->publishers($page);
        if ($page > $result['totalPages']) {
            throw $this->createNotFoundException();
        }

        return $this->render('game_publisher_directory/index.html.twig', [
            ...$result,
            'page' => $page,
        ]);
    }

    #[Route('/{slug}', name: 'app_game_publisher_show', requirements: ['slug' => '[a-z0-9-]{1,180}'], methods: ['GET'])]
    public function show(string $slug, Request $request): Response
    {
        $this->assertAvailable();
        $page = $this->page($request);
        $result = $this->publishers->publisher($slug, $page);
        if ($result === null || $page > $result['totalPages']) {
            throw $this->createNotFoundException();
        }

        return $this->render('game_publisher_directory/show.html.twig', [
            ...$result,
            'page' => $page,
        ]);
    }

    private function page(Request $request): int
    {
        $parameters = $request->query->all();
        $value = $parameters['page'] ?? '1';
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,4}$/D', $value) !== 1) {
            throw $this->createNotFoundException();
        }

        return (int) $value;
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }
}

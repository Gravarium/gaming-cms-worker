<?php

declare(strict_types=1);

namespace App\Controller\GameCatalogue;

use App\GameComparison\ComparisonCatalogue;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GameComparisonController extends AbstractController
{
    public function __construct(
        private readonly ComparisonCatalogue $catalogue,
        private readonly CmsModuleManager $modules,
    ) {}

    #[Route('/games/compare', name: 'app_game_comparison', priority: 10, methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        $raw = $request->query->all()['games'] ?? '';
        if (!is_string($raw)) {
            throw $this->createNotFoundException();
        }

        try {
            $games = $raw === '' ? [] : $this->catalogue->compare($raw);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('game_comparison/index.html.twig', [
            'selection' => $raw,
            'games' => $games,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

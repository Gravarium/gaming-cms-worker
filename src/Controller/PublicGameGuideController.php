<?php

declare(strict_types=1);

namespace App\Controller;

use App\GameGuide\PublicGameGuideQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/gaming/guides', name: 'app_gaming_guide_')]
final class PublicGameGuideController extends AbstractController
{
    public function __construct(private readonly PublicGameGuideQuery $guides)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = trim($request->query->getString('q'));
        if (mb_strlen($query) > 100) {
            $query = mb_substr($query, 0, 100);
        }
        $type = $request->query->getString('type');
        if ($type !== '' && !in_array($type, ['build', 'tier_list'], true)) {
            throw $this->createNotFoundException();
        }
        $game = $request->query->getString('game');
        if ($game !== '' && !$this->guides->hasEnabledGame($game)) {
            throw $this->createNotFoundException();
        }
        $page = $request->query->getInt('page', 1);
        if ($page < 1 || $page > 10_000) {
            throw $this->createNotFoundException();
        }

        $total = $this->guides->count($query, $type, $game);
        $pages = max(1, (int) ceil($total / PublicGameGuideQuery::PAGE_SIZE));
        if ($total > 0 && $page > $pages) {
            throw $this->createNotFoundException();
        }

        return $this->render('public/index.html.twig', [
            'guides' => $this->guides->directory(
                $query,
                $type,
                $game,
                PublicGameGuideQuery::PAGE_SIZE,
                ($page - 1) * PublicGameGuideQuery::PAGE_SIZE,
            ),
            'games' => $this->guides->enabledGames(),
            'query' => $query,
            'type' => $type,
            'game' => $game,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        $guide = $this->guides->findPublic($id);
        if ($guide === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('public/show.html.twig', ['guide' => $guide]);
    }
}

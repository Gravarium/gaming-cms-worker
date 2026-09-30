<?php

declare(strict_types=1);

namespace App\Controller;

use App\CompetitionSeason\PublicSeasonBrowser;
use App\Entity\Competition\CompetitionSeason;
use App\Module\CmsModuleManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/competition-seasons')]
final class PublicCompetitionSeasonController extends AbstractController
{
    public function __construct(
        private readonly PublicSeasonBrowser $browser,
        private readonly CmsModuleManager $modules,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_public_competition_seasons', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->assertAvailable();
        $page = $this->page($request);
        $result = $this->browser->seasons($page);
        if ($page > 1 && ($page - 1) * PublicSeasonBrowser::PAGE_SIZE >= $result['total']) {
            throw $this->createNotFoundException();
        }

        return $this->render('competition_season/index.html.twig', [
            'seasons' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pageSize' => PublicSeasonBrowser::PAGE_SIZE,
        ]);
    }

    #[Route('/{id}', name: 'app_public_competition_season_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id, Request $request): Response
    {
        $this->assertAvailable();
        $page = $this->page($request);
        $season = $this->entityManager->find(CompetitionSeason::class, $id);
        if (!$season instanceof CompetitionSeason) {
            throw $this->createNotFoundException();
        }
        $result = $this->browser->competitions($season, $page);
        if ($result['total'] === 0 || ($page - 1) * PublicSeasonBrowser::PAGE_SIZE >= $result['total']) {
            throw $this->createNotFoundException();
        }

        return $this->render('competition_season/show.html.twig', [
            'season' => $season,
            'competitions' => $result['competitions'],
            'total' => $result['total'],
            'page' => $page,
            'pageSize' => PublicSeasonBrowser::PAGE_SIZE,
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
        $raw = $request->query->all()['page'] ?? '1';
        if (!is_string($raw) || !preg_match('/^[1-9][0-9]{0,3}$/D', $raw)) {
            throw $this->createNotFoundException();
        }

        return (int) $raw;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\CompetitionResultsArchive\ConfirmedResultsPage;
use App\CompetitionResultsArchive\ResultsCsv;
use App\Entity\Competition\Competition;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicCompetitionResultsArchiveController extends AbstractController
{
    public function __construct(
        private readonly ConfirmedResultsPage $results,
        private readonly ResultsCsv $csv,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/competitions/{id}/results/archive', name: 'app_competition_results_archive', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function index(Competition $competition, Request $request): Response
    {
        $this->assertPublic($competition);
        $page = $this->page($request);
        $results = $this->results->forCompetition($competition, $page);

        return $this->render('competition_results_archive/index.html.twig', [
            'competition' => $competition,
            'matches' => $results['matches'],
            'hasMore' => $results['hasMore'],
            'page' => $page,
            'maxPage' => ConfirmedResultsPage::MAX_PAGE,
        ]);
    }

    #[Route('/competitions/{id}/results/archive.csv', name: 'app_competition_results_archive_csv', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function csv(Competition $competition, Request $request): Response
    {
        $this->assertPublic($competition);
        $page = $this->page($request);
        $results = $this->results->forCompetition($competition, $page);

        return new Response($this->csv->render($results['matches']), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="competition-'.$competition->getId().'-results-page-'.$page.'.csv"',
            'Cache-Control' => 'public, max-age=60',
        ]);
    }

    private function assertPublic(Competition $competition): void
    {
        if (!$this->modules->isEnabled('gaming')
            || !$competition->isPublic()
            || $competition->getStatus() === Competition::STATUS_DRAFT
            || $competition->getGame()?->isEnabled() !== true
        ) {
            throw $this->createNotFoundException();
        }
    }

    private function page(Request $request): int
    {
        $page = $request->query->getString('page', '1');
        if (preg_match('/^[1-9][0-9]{0,2}$/D', $page) !== 1) {
            throw $this->createNotFoundException();
        }
        $number = (int) $page;
        if ($number > ConfirmedResultsPage::MAX_PAGE) {
            throw $this->createNotFoundException();
        }

        return $number;
    }
}

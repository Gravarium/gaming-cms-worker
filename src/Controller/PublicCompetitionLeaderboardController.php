<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Competition\Competition;
use App\Entity\User;
use App\Gaming\Competition\CompetitionVisibilityPolicy;
use App\Module\CmsModuleManager;
use App\Widget\CompetitionStandings\CompetitionStandingsQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/competitions')]
final class PublicCompetitionLeaderboardController extends AbstractController
{
    public function __construct(
        private readonly CompetitionStandingsQuery $standings,
        private readonly CompetitionVisibilityPolicy $visibility,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/{id}/leaderboard', name: 'app_competition_leaderboard', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(Competition $competition): Response
    {
        $this->assertAvailable();
        $viewer = $this->getUser();
        if ($viewer !== null && !$viewer instanceof User) {
            throw $this->createNotFoundException();
        }
        if (!$this->visibility->canView($competition, $viewer instanceof User ? $viewer : null)) {
            throw $this->createNotFoundException();
        }

        $leaderboard = $this->standings->forCompetition($competition);

        return $this->render('competition_leaderboard/index.html.twig', [
            'competition' => $competition,
            'standings' => $leaderboard['standings'],
            'results' => $leaderboard['results'],
        ]);
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }
}

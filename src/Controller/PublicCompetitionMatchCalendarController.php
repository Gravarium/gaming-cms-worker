<?php

declare(strict_types=1);

namespace App\Controller;

use App\CompetitionMatchCalendar\MatchCalendarFeed;
use App\CompetitionMatchCalendar\PublicMatchSchedule;
use App\Module\CmsModuleManager;
use App\Repository\GameRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicCompetitionMatchCalendarController extends AbstractController
{
    public function __construct(
        private readonly CmsModuleManager $modules,
        private readonly GameRepository $games,
        private readonly PublicMatchSchedule $schedule,
        private readonly MatchCalendarFeed $feed,
    ) {
    }

    #[Route('/competitions/matches', name: 'app_competition_match_calendar', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->assertAvailable();
        $game = $this->selectedGame($request);

        return $this->render('competition_match_calendar/index.html.twig', [
            'matches' => $this->schedule->upcoming($game?->getSlug()),
            'games' => $this->games->findEnabled(),
            'selectedGame' => $game,
        ]);
    }

    #[Route('/competitions/matches.ics', name: 'app_competition_match_calendar_ics', methods: ['GET'])]
    public function feed(Request $request): Response
    {
        $this->assertAvailable();
        $game = $this->selectedGame($request);
        $body = $this->feed->render(
            $this->schedule->upcoming($game?->getSlug()),
            fn (int $id): string => $this->generateUrl('app_competition_show', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL),
        );

        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="competition-matches.ics"',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }

    private function selectedGame(Request $request): ?\App\Entity\Game
    {
        $query = $request->query->all();
        if (!array_key_exists('game', $query) || $query['game'] === '') {
            return null;
        }
        $slug = $query['game'];
        if (!is_string($slug) || strlen($slug) > 140 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            throw $this->createNotFoundException();
        }

        $game = $this->games->findOneBy(['slug' => $slug, 'enabled' => true]);
        if (!$game instanceof \App\Entity\Game) {
            throw $this->createNotFoundException();
        }

        return $game;
    }
}

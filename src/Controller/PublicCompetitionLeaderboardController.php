<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\User;
use App\Gaming\Competition\CompetitionVisibilityPolicy;
use App\Module\CmsModuleManager;
use App\Repository\Competition\CompetitionMatchRepository;
use App\Repository\Competition\CompetitionParticipantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/competitions')]
final class PublicCompetitionLeaderboardController extends AbstractController
{
    public function __construct(
        private readonly CompetitionParticipantRepository $participants,
        private readonly CompetitionMatchRepository $matches,
        private readonly CompetitionVisibilityPolicy $visibility,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/{id}/leaderboard', name: 'app_competition_leaderboard', requirements: ['id' => '\\d+'], methods: ['GET'])]
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

        /**
         * @var array<int, array{
         *     participant: CompetitionParticipant,
         *     played: int,
         *     wins: int,
         *     draws: int,
         *     losses: int,
         *     scoreFor: int,
         *     scoreAgainst: int,
         *     difference: int,
         *     points: int
         * }> $standings
         */
        $standings = [];
        foreach ($this->participants->forCompetition($competition) as $participant) {
            $participantId = $participant->getId();
            if ($participantId === null || !$participant->isActive()) {
                continue;
            }
            $standings[$participantId] = [
                'participant' => $participant,
                'played' => 0,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
                'scoreFor' => 0,
                'scoreAgainst' => 0,
                'difference' => 0,
                'points' => 0,
            ];
        }

        /** @var list<array{round: int, bracket: string, sequence: int, participantA: string, participantB: string, scoreA: int, scoreB: int}> $results */
        $results = [];
        foreach ($this->matches->forCompetition($competition) as $match) {
            $participantA = $match->getParticipantA();
            $participantB = $match->getParticipantB();
            $scoreA = $match->getScoreA();
            $scoreB = $match->getScoreB();
            $participantAId = $participantA?->getId();
            $participantBId = $participantB?->getId();
            if (!$match->isConfirmed()
                || !$participantA instanceof CompetitionParticipant
                || !$participantB instanceof CompetitionParticipant
                || $scoreA === null
                || $scoreB === null
                || $participantAId === null
                || $participantBId === null
                || !isset($standings[$participantAId], $standings[$participantBId])
            ) {
                continue;
            }

            $standings[$participantAId]['played']++;
            $standings[$participantBId]['played']++;
            $standings[$participantAId]['scoreFor'] += $scoreA;
            $standings[$participantAId]['scoreAgainst'] += $scoreB;
            $standings[$participantBId]['scoreFor'] += $scoreB;
            $standings[$participantBId]['scoreAgainst'] += $scoreA;
            $standings[$participantAId]['difference'] += $scoreA - $scoreB;
            $standings[$participantBId]['difference'] += $scoreB - $scoreA;

            if ($scoreA > $scoreB) {
                ++$standings[$participantAId]['wins'];
                ++$standings[$participantBId]['losses'];
                $standings[$participantAId]['points'] += 3;
            } elseif ($scoreB > $scoreA) {
                ++$standings[$participantBId]['wins'];
                ++$standings[$participantAId]['losses'];
                $standings[$participantBId]['points'] += 3;
            } else {
                ++$standings[$participantAId]['draws'];
                ++$standings[$participantBId]['draws'];
                ++$standings[$participantAId]['points'];
                ++$standings[$participantBId]['points'];
            }

            $results[] = [
                'round' => $match->getRoundNumber(),
                'bracket' => $match->getBracket(),
                'sequence' => $match->getSequence(),
                'participantA' => $participantA->getName(),
                'participantB' => $participantB->getName(),
                'scoreA' => $scoreA,
                'scoreB' => $scoreB,
            ];
        }

        $standings = array_values($standings);
        usort($standings, static function (array $left, array $right): int {
            foreach (['points', 'wins', 'difference', 'scoreFor'] as $key) {
                $comparison = $right[$key] <=> $left[$key];
                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return strcasecmp($left['participant']->getName(), $right['participant']->getName());
        });

        return $this->render('competition_leaderboard/index.html.twig', [
            'competition' => $competition,
            'standings' => $standings,
            'results' => $results,
        ]);
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }
}

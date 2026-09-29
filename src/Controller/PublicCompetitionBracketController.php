<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\User;
use App\Gaming\Competition\CompetitionVisibilityPolicy;
use App\Module\CmsModuleManager;
use App\Repository\Competition\CompetitionMatchRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicCompetitionBracketController extends AbstractController
{
    public function __construct(
        private readonly CompetitionMatchRepository $matches,
        private readonly CompetitionVisibilityPolicy $visibility,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/competitions/{id}/bracket', name: 'app_competition_bracket', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(Competition $competition): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        $viewer = $this->getUser();
        if ($viewer !== null && !$viewer instanceof User) {
            throw $this->createNotFoundException();
        }

        if (!$this->visibility->canView($competition, $viewer instanceof User ? $viewer : null)) {
            throw $this->createNotFoundException();
        }

        /** @var array<string, array<int, list<CompetitionMatch>>> $brackets */
        $brackets = [];
        foreach ($this->matches->forCompetition($competition) as $match) {
            $bracket = $match->getBracket();
            $round = $match->getRoundNumber();
            $brackets[$bracket][$round] ??= [];
            $brackets[$bracket][$round][] = $match;
        }

        foreach ($brackets as &$rounds) {
            ksort($rounds, SORT_NUMERIC);
        }
        unset($rounds);
        /** @var array<string, int> $bracketOrder */
        $bracketOrder = [
            CompetitionMatch::BRACKET_WINNERS => 0,
            CompetitionMatch::BRACKET_LOSERS => 1,
            CompetitionMatch::BRACKET_GROUP => 2,
            CompetitionMatch::BRACKET_SWISS => 3,
            CompetitionMatch::BRACKET_ROUND_ROBIN => 4,
        ];
        uksort($brackets, static function (string $left, string $right) use ($bracketOrder): int {
            $order = ($bracketOrder[$left] ?? PHP_INT_MAX) <=> ($bracketOrder[$right] ?? PHP_INT_MAX);

            return $order !== 0 ? $order : strcmp($left, $right);
        });

        return $this->render('competition_bracket/show.html.twig', [
            'competition' => $competition,
            'brackets' => $brackets,
        ]);
    }
}

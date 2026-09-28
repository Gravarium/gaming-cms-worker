<?php

declare(strict_types=1);

namespace App\Widget;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionParticipant;
use App\Repository\PublicCompetitionBracketRepository;
use App\Widget\CompetitionStandings\CompetitionStandingsQuery;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final readonly class CompetitionStandingsWidgetProvider implements WidgetProvider
{
    private const KEY = 'gaming.competition-standings';
    private const MAX_COMPETITIONS = 4;
    private const MAX_STANDINGS = 3;
    private const CACHE_KEY = '_cms_widget_data_gaming.competition-standings';

    public function __construct(
        private PublicCompetitionBracketRepository $competitions,
        private CompetitionStandingsQuery $standings,
        private RequestStack $requests,
        Environment $twig,
    ) {
        $loader = $twig->getLoader();
        if (!$loader instanceof FilesystemLoader) {
            throw new \LogicException('Competition standings widget requires a filesystem Twig loader.');
        }

        $loader->addPath(__DIR__.'/CompetitionStandings/Templates');
    }

    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'Aktuelle Competition-Ranglisten',
                'gaming',
                'widget/competition_standings.html.twig',
                [],
                false,
            ),
        ];
    }

    /**
     * @param array<string, string|int|bool> $config
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        if ($key !== self::KEY) {
            return [];
        }

        $request = $this->requests->getCurrentRequest();
        $cached = $request?->attributes->get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $items = [];
        foreach ($this->competitions->publicCompetitionsWithMatches() as $competition) {
            $board = $this->standings->forCompetition($competition);
            if ($board['results'] === [] || $board['standings'] === []) {
                continue;
            }

            $items[] = [
                'competition' => $competition,
                'standings' => array_slice($board['standings'], 0, self::MAX_STANDINGS),
            ];
            if (count($items) >= self::MAX_COMPETITIONS) {
                break;
            }
        }

        $data = ['items' => $items];
        $request?->attributes->set(self::CACHE_KEY, $data);

        return $data;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Competition\Competition;
use App\Entity\Game;
use App\Module\CmsModuleManager;
use App\Repository\Competition\CompetitionRepository;
use App\Repository\GameRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CompetitionCalendarController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitions,
        private readonly GameRepository $games,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/competitions/calendar', name: 'app_competition_calendar', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->assertGamingEnabled();
        $selectedGame = $this->selectedGame($request);

        return $this->render('competition_calendar/index.html.twig', [
            'competitions' => $this->upcomingCompetitions($selectedGame),
            'games' => $this->games->findEnabled(),
            'selectedGame' => $selectedGame,
        ]);
    }

    #[Route('/competitions/calendar.ics', name: 'app_competition_calendar_ics', methods: ['GET'])]
    public function feed(Request $request): Response
    {
        $this->assertGamingEnabled();
        $selectedGame = $this->selectedGame($request);
        $body = $this->buildCalendar($this->upcomingCompetitions($selectedGame));

        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="competitions.ics"',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    private function assertGamingEnabled(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }

    private function selectedGame(Request $request): ?Game
    {
        $query = $request->query->all();
        if (!array_key_exists('game', $query)) {
            return null;
        }

        $slug = $query['game'];
        if ($slug === '') {
            return null;
        }
        if (!is_string($slug) || strlen($slug) > 140 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            throw $this->createNotFoundException();
        }

        $game = $this->games->findOneBy(['slug' => $slug, 'enabled' => true]);
        if (!$game instanceof Game) {
            throw $this->createNotFoundException();
        }

        return $game;
    }

    /**
     * @return list<Competition>
     */
    private function upcomingCompetitions(?Game $selectedGame): array
    {
        $now = new \DateTimeImmutable();

        return array_values(array_filter(
            $this->competitions->publicCompetitions(),
            static function (Competition $competition) use ($now, $selectedGame): bool {
                if ($competition->getStatus() !== Competition::STATUS_OPEN || $competition->getStartsAt() <= $now) {
                    return false;
                }

                $endsAt = $competition->getEndsAt();
                if ($endsAt !== null && $endsAt <= $competition->getStartsAt()) {
                    return false;
                }

                return $selectedGame === null || $competition->getGame()?->getSlug() === $selectedGame->getSlug();
            },
        ));
    }

    /**
     * @param list<Competition> $competitions
     */
    private function buildCalendar(array $competitions): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Gravarium Gaming CMS//Competition Calendar//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        foreach ($competitions as $competition) {
            $id = $competition->getId();
            $game = $competition->getGame();
            if ($id === null || !$game instanceof Game) {
                continue;
            }

            $eventLines = [
                'BEGIN:VEVENT',
                'UID:competition-'.$id.'@gaming-cms',
                'DTSTAMP:'.$this->formatUtc($competition->getCreatedAt()),
                'DTSTART:'.$this->formatUtc($competition->getStartsAt()),
            ];

            $endsAt = $competition->getEndsAt();
            if ($endsAt instanceof \DateTimeImmutable) {
                $eventLines[] = 'DTEND:'.$this->formatUtc($endsAt);
            } else {
                $eventLines[] = 'DURATION:PT2H';
            }

            $detailUrl = $this->generateUrl(
                'app_competition_show',
                ['id' => $id],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );
            $description = $game->getName();
            if ($competition->getDescription() !== '') {
                $description .= "\n".$competition->getDescription();
            }
            array_push(
                $eventLines,
                'SUMMARY:'.$this->escapeCalendarText($competition->getName()),
                'DESCRIPTION:'.$this->escapeCalendarText($description),
                'URL:'.$detailUrl,
                'END:VEVENT',
            );
            array_push($lines, ...$eventLines);
        }

        $lines[] = 'END:VCALENDAR';
        $folded = array_map(fn (string $line): string => $this->foldCalendarLine($line), $lines);

        return implode("\r\n", $folded)."\r\n";
    }

    private function formatUtc(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z');
    }

    private function escapeCalendarText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';

        return str_replace(
            ['\\', ';', ',', "\n"],
            ['\\\\', '\\;', '\\,', '\\n'],
            $value,
        );
    }

    private function foldCalendarLine(string $line): string
    {
        $chunks = [];
        $limit = 75;

        while (strlen($line) > $limit) {
            $cut = $limit;
            while ($cut > 0 && isset($line[$cut]) && (ord($line[$cut]) & 0xC0) === 0x80) {
                --$cut;
            }
            if ($cut === 0) {
                break;
            }

            $chunks[] = substr($line, 0, $cut);
            $line = substr($line, $cut);
            $limit = 74;
        }

        $chunks[] = $line;

        return implode("\r\n ", $chunks);
    }
}

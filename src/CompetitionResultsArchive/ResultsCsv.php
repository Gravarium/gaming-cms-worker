<?php

declare(strict_types=1);

namespace App\CompetitionResultsArchive;

use App\Entity\Competition\CompetitionMatch;

final class ResultsCsv
{
    /** @param list<CompetitionMatch> $matches */
    public function render(array $matches): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot create CSV stream.');
        }

        try {
            fputcsv($stream, ['Round', 'Bracket', 'Match', 'Participant A', 'Score A', 'Score B', 'Participant B'], ',', '"', '');
            foreach ($matches as $match) {
                fputcsv($stream, [
                    $match->getRoundNumber(),
                    $this->safeCell($match->getBracket()),
                    $match->getSequence(),
                    $this->safeCell($match->getParticipantA()?->getName() ?? ''),
                    $match->getScoreA(),
                    $match->getScoreB(),
                    $this->safeCell($match->getParticipantB()?->getName() ?? ''),
                ], ',', '"', '');
            }
            rewind($stream);
            $content = stream_get_contents($stream);
            if ($content === false) {
                throw new \RuntimeException('Cannot read CSV stream.');
            }

            return $content;
        } finally {
            fclose($stream);
        }
    }

    private function safeCell(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], ' ', $value);

        return preg_match('/^\s*[=+@\-]/u', $value) === 1 ? "'".$value : $value;
    }
}

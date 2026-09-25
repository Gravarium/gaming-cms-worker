<?php

declare(strict_types=1);

namespace App\Gaming\Competition;

use App\Entity\Competition\Competition;

final class CompetitionFormat
{
    /** @return list<string> */
    public function all(): array { return Competition::FORMATS; }

    public function isElimination(string $format): bool
    {
        return in_array($format, [Competition::FORMAT_SINGLE_ELIMINATION, Competition::FORMAT_DOUBLE_ELIMINATION], true);
    }

    public function bracketFor(string $format): string
    {
        return match ($format) {
            Competition::FORMAT_SINGLE_ELIMINATION, Competition::FORMAT_DOUBLE_ELIMINATION => 'winners',
            Competition::FORMAT_ROUND_ROBIN => 'round_robin',
            Competition::FORMAT_SWISS => 'swiss',
            Competition::FORMAT_GROUP_STAGE => 'group',
            default => throw new \InvalidArgumentException('Unsupported competition format.'),
        };
    }

    public function validateParticipantCount(Competition $competition, int $count): void
    {
        if ($count < 2) { throw new \DomainException('A competition needs at least two active participants.'); }
        if ($this->isElimination($competition->getFormat()) && $count > 128) {
            throw new \DomainException('Elimination competitions are limited to 128 participants.');
        }
    }
}

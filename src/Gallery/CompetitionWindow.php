<?php

declare(strict_types=1);

namespace App\Gallery;

final readonly class CompetitionWindow
{
    public function __construct(
        public \DateTimeImmutable $submissionsOpenAt,
        public \DateTimeImmutable $submissionsCloseAt,
        public \DateTimeImmutable $votingOpenAt,
        public \DateTimeImmutable $votingCloseAt,
    ) {
        if (!($submissionsOpenAt < $submissionsCloseAt && $submissionsCloseAt <= $votingOpenAt && $votingOpenAt < $votingCloseAt)) {
            throw new \InvalidArgumentException('Competition windows must be ordered and non-overlapping.');
        }
    }

    public function submissionsOpen(\DateTimeImmutable $now): bool
    {
        return $now >= $this->submissionsOpenAt && $now < $this->submissionsCloseAt;
    }

    public function votingOpen(\DateTimeImmutable $now): bool
    {
        return $now >= $this->votingOpenAt && $now < $this->votingCloseAt;
    }
}

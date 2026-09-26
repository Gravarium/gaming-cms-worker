<?php

declare(strict_types=1);

namespace App\Gaming\Event;

final readonly class EventOccurrence
{
    public function __construct(
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
    ) {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('Event end must be after its start.');
        }
    }
}

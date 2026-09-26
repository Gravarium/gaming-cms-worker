<?php

declare(strict_types=1);

namespace App\Gaming\Event;

final readonly class RecurringEventSchedule
{
    public const NONE = 'none';
    public const WEEKLY = 'weekly';
    public const BIWEEKLY = 'biweekly';

    public function __construct(
        public string $timezone,
        public string $frequency = self::NONE,
        public int $occurrenceLimit = 1,
    ) {
        if (!in_array($frequency, [self::NONE, self::WEEKLY, self::BIWEEKLY], true)) {
            throw new \InvalidArgumentException('Unsupported recurrence frequency.');
        }
        if ($occurrenceLimit < 1 || $occurrenceLimit > 52) {
            throw new \InvalidArgumentException('Occurrence limit must be between 1 and 52.');
        }
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new \InvalidArgumentException('Unknown event timezone.');
        }
    }

    /** @return list<EventOccurrence> */
    public function expand(\DateTimeImmutable $localStart, \DateTimeImmutable $localEnd): array
    {
        $zone = new \DateTimeZone($this->timezone);
        $start = $localStart->setTimezone($zone);
        $end = $localEnd->setTimezone($zone);
        $step = match ($this->frequency) {
            self::WEEKLY => new \DateInterval('P7D'),
            self::BIWEEKLY => new \DateInterval('P14D'),
            default => null,
        };
        $limit = $step === null ? 1 : $this->occurrenceLimit;
        $occurrences = [];
        for ($index = 0; $index < $limit; ++$index) {
            $occurrences[] = new EventOccurrence(
                $start->setTimezone(new \DateTimeZone('UTC')),
                $end->setTimezone(new \DateTimeZone('UTC')),
            );
            if ($step !== null) {
                $start = $start->add($step);
                $end = $end->add($step);
            }
        }

        return $occurrences;
    }
}

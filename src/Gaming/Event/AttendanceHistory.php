<?php

declare(strict_types=1);

namespace App\Gaming\Event;

final class AttendanceHistory
{
    /** @var list<array{status: string, occurredAt: \DateTimeImmutable, actorId: int}> */
    private array $entries = [];

    public function record(string $status, \DateTimeImmutable $occurredAt, int $actorId): void
    {
        if (!in_array($status, ['checked_in', 'attended', 'absent', 'excused'], true) || $actorId < 1) {
            throw new \InvalidArgumentException('Invalid attendance evidence.');
        }
        $previous = $this->entries === [] ? null : $this->entries[count($this->entries) - 1];
        if ($previous !== null && $occurredAt < $previous['occurredAt']) {
            throw new \DomainException('Attendance history is append-only and chronological.');
        }
        $this->entries[] = ['status' => $status, 'occurredAt' => $occurredAt, 'actorId' => $actorId];
    }

    /** @return list<array{status: string, occurredAt: \DateTimeImmutable, actorId: int}> */
    public function entries(): array
    {
        return $this->entries;
    }
}

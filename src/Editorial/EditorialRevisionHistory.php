<?php

declare(strict_types=1);

namespace App\Editorial;

final class EditorialRevisionHistory
{
    /** @var list<array{version: int, summary: string, actorId: int, createdAt: \DateTimeImmutable}> */
    private array $revisions = [];

    public function append(string $summary, int $actorId, \DateTimeImmutable $createdAt): void
    {
        if (trim($summary) === '' || mb_strlen($summary) > 500 || $actorId < 1) {
            throw new \InvalidArgumentException('Complete bounded revision evidence is required.');
        }
        $previous = $this->revisions === [] ? null : $this->revisions[count($this->revisions) - 1];
        if ($previous !== null && $createdAt < $previous['createdAt']) {
            throw new \DomainException('Editorial revisions are append-only and chronological.');
        }
        $this->revisions[] = ['version' => count($this->revisions) + 1, 'summary' => $summary, 'actorId' => $actorId, 'createdAt' => $createdAt];
    }

    /** @return list<array{version: int, summary: string, actorId: int, createdAt: \DateTimeImmutable}> */
    public function revisions(): array
    {
        return $this->revisions;
    }
}

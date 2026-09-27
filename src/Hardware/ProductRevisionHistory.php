<?php

declare(strict_types=1);

namespace App\Hardware;

final class ProductRevisionHistory
{
    /** @var list<array{version: int, actorId: int, summary: string, at: \DateTimeImmutable}> */
    private array $revisions = [];

    public function append(int $actorId, string $summary, \DateTimeImmutable $at): void
    {
        if ($actorId < 1 || trim($summary) === '' || mb_strlen($summary) > 500) {
            throw new \InvalidArgumentException('Complete revision evidence is required.');
        }
        $previous = $this->revisions === [] ? null : $this->revisions[count($this->revisions) - 1];
        if ($previous !== null && $at < $previous['at']) {
            throw new \DomainException('Product revisions are append-only and chronological.');
        }
        $this->revisions[] = ['version' => count($this->revisions) + 1, 'actorId' => $actorId, 'summary' => $summary, 'at' => $at];
    }

    /** @return list<array{version: int, actorId: int, summary: string, at: \DateTimeImmutable}> */
    public function revisions(): array { return $this->revisions; }
}

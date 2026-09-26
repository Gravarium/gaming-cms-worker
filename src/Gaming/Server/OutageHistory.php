<?php

declare(strict_types=1);

namespace App\Gaming\Server;

final class OutageHistory
{
    /** @var list<array{startedAt: \DateTimeImmutable, endedAt: \DateTimeImmutable|null}> */
    private array $outages = [];

    public function offline(\DateTimeImmutable $at): void
    {
        $current = $this->outages === [] ? null : $this->outages[count($this->outages) - 1];
        if ($current !== null && $current['endedAt'] === null) {
            return;
        }
        $this->outages[] = ['startedAt' => $at, 'endedAt' => null];
    }

    public function online(\DateTimeImmutable $at): void
    {
        if ($this->outages === []) {
            return;
        }
        $index = count($this->outages) - 1;
        $current = $this->outages[$index];
        if ($current['endedAt'] !== null) {
            return;
        }
        if ($at <= $current['startedAt']) {
            throw new \DomainException('Outage end must follow its start.');
        }
        $this->outages[$index]['endedAt'] = $at;
    }

    /** @return list<array{startedAt: \DateTimeImmutable, endedAt: \DateTimeImmutable|null}> */
    public function entries(): array
    {
        return $this->outages;
    }
}

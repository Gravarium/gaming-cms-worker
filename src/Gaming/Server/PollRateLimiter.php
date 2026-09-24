<?php

declare(strict_types=1);

namespace App\Gaming\Server;

final class PollRateLimiter
{
    /** @var array<string, \DateTimeImmutable> */
    private array $lastPoll = [];

    public function claim(string $serverKey, \DateTimeImmutable $now, int $minimumIntervalSeconds = 30): void
    {
        if ($minimumIntervalSeconds < 5 || $minimumIntervalSeconds > 3600) {
            throw new \InvalidArgumentException('Polling interval outside safe bounds.');
        }
        $last = $this->lastPoll[$serverKey] ?? null;
        if ($last !== null && $now->getTimestamp() - $last->getTimestamp() < $minimumIntervalSeconds) {
            throw new \RuntimeException('Server polling is rate limited.');
        }
        $this->lastPoll[$serverKey] = $now;
    }
}

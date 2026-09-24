<?php

declare(strict_types=1);

namespace App\Gaming\Server;

final class PollCircuitBreaker
{
    private int $failures = 0;
    private ?\DateTimeImmutable $openUntil = null;

    public function __construct(private readonly int $threshold = 3, private readonly int $cooldownSeconds = 300)
    {
        if ($threshold < 1 || $threshold > 20 || $cooldownSeconds < 1 || $cooldownSeconds > 86400) {
            throw new \InvalidArgumentException('Invalid circuit-breaker bounds.');
        }
    }

    public function assertAvailable(\DateTimeImmutable $now): void
    {
        if ($this->openUntil !== null && $now < $this->openUntil) {
            throw new \RuntimeException('Polling circuit is open.');
        }
        if ($this->openUntil !== null && $now >= $this->openUntil) {
            $this->failures = 0;
            $this->openUntil = null;
        }
    }

    public function recordSuccess(): void
    {
        $this->failures = 0;
        $this->openUntil = null;
    }

    public function recordFailure(\DateTimeImmutable $now): void
    {
        ++$this->failures;
        if ($this->failures >= $this->threshold) {
            $this->openUntil = $now->modify('+'.$this->cooldownSeconds.' seconds');
        }
    }
}

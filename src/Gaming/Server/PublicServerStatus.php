<?php

declare(strict_types=1);

namespace App\Gaming\Server;

final readonly class PublicServerStatus
{
    public function __construct(
        public string $state,
        public int $players,
        public int $slots,
        public ?int $pingMs,
        public ?string $map,
        public ?string $version,
        public bool $maintenance,
        public \DateTimeImmutable $observedAt,
    ) {
        if (!in_array($state, ['online', 'offline', 'unknown'], true) || $players < 0 || $slots < 0 || $players > $slots) {
            throw new \InvalidArgumentException('Invalid public server status.');
        }
        if ($pingMs !== null && ($pingMs < 0 || $pingMs > 120000)) {
            throw new \InvalidArgumentException('Invalid server latency.');
        }
    }

    public function isStale(\DateTimeImmutable $now, int $maximumAgeSeconds = 300): bool
    {
        return $maximumAgeSeconds < 1 || $now->getTimestamp() - $this->observedAt->getTimestamp() > $maximumAgeSeconds;
    }
}

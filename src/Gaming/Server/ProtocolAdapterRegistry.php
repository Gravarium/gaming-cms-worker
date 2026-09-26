<?php

declare(strict_types=1);

namespace App\Gaming\Server;

final class ProtocolAdapterRegistry
{
    /** @var array<string, callable(string, int): array<string, scalar|null>> */
    private array $adapters = [];

    /** @param callable(string, int): array<string, scalar|null> $adapter */
    public function register(string $protocol, callable $adapter): void
    {
        if (!in_array($protocol, ['minecraft-status', 'source-query', 'http-health'], true)) {
            throw new \DomainException('Unknown protocol adapter denied.');
        }
        $this->adapters[$protocol] = $adapter;
    }

    /** @return array<string, scalar|null> */
    public function poll(string $protocol, string $host, int $port): array
    {
        if (!isset($this->adapters[$protocol])) {
            throw new \RuntimeException('Approved protocol adapter unavailable.');
        }

        return ($this->adapters[$protocol])($host, $port);
    }
}

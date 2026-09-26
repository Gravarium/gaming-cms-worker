<?php

declare(strict_types=1);

namespace App\Gaming\Server;

final class ServerTargetPolicy
{
    /** @param list<string> $approvedHosts */
    public function __construct(private readonly array $approvedHosts)
    {
    }

    public function assertAllowed(string $host, int $port, string $protocol): void
    {
        $normalized = strtolower(rtrim(trim($host), '.'));
        if ($normalized === '' || strlen($normalized) > 253 || $port < 1 || $port > 65535) {
            throw new \DomainException('Invalid server target.');
        }
        if (!in_array($protocol, ['minecraft-status', 'source-query', 'http-health'], true)) {
            throw new \DomainException('Protocol adapter is not approved.');
        }
        if (filter_var($normalized, FILTER_VALIDATE_IP) !== false && filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new \DomainException('Private or reserved network target denied.');
        }
        if (!in_array($normalized, array_map(static fn (string $value): string => strtolower(rtrim($value, '.')), $this->approvedHosts), true)) {
            throw new \DomainException('Target host is not allowlisted.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\ExternalConnector;

/**
 * Provider boundary shared by capability-specific adapters.
 *
 * Credentials and provider configuration must remain behind the adapter and
 * must never be returned by this contract.
 */
interface ExternalConnectorAdapter
{
    public function providerKey(): string;

    public function supports(string $capability): bool;
}

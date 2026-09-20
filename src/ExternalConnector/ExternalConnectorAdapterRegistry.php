<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final class ExternalConnectorAdapterRegistry
{
    /** @var array<string, ExternalConnectorAdapter> */
    private array $adapters = [];

    /** @param iterable<ExternalConnectorAdapter> $adapters */
    public function __construct(iterable $adapters)
    {
        foreach ($adapters as $adapter) {
            $providerKey = strtolower(trim($adapter->providerKey()));

            if ($providerKey === '' || preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $providerKey) !== 1) {
                throw new \LogicException('External connector adapters must expose a valid provider key.');
            }

            if (isset($this->adapters[$providerKey])) {
                throw new \LogicException(sprintf('External connector adapter "%s" is registered more than once.', $providerKey));
            }

            $this->adapters[$providerKey] = $adapter;
        }

        ksort($this->adapters);
    }

    /** @return list<string> */
    public function providerKeys(): array
    {
        return array_keys($this->adapters);
    }

    public function has(string $providerKey): bool
    {
        return isset($this->adapters[strtolower(trim($providerKey))]);
    }

    public function forProvider(string $providerKey): ExternalConnectorAdapter
    {
        $providerKey = strtolower(trim($providerKey));

        return $this->adapters[$providerKey]
            ?? throw new \RuntimeException(sprintf('No external connector adapter is registered for provider "%s".', $providerKey));
    }

    public function forTarget(ExternalConnectorTargetDefinition $target): ExternalConnectorAdapter
    {
        $adapter = $this->forProvider($target->providerKey);

        if (!$adapter->supports($target->capability)) {
            throw new \RuntimeException(sprintf(
                'External connector adapter "%s" does not support capability "%s".',
                $target->providerKey,
                $target->capability,
            ));
        }

        return $adapter;
    }
}

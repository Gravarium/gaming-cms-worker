<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final class ExternalConnectorAdapterRegistry
{
    private const MAX_PROVIDER_KEY_BYTES = 64;

    /** @var array<string, ExternalConnectorAdapter> */
    private array $adapters = [];

    /** @param iterable<ExternalConnectorAdapter> $adapters */
    public function __construct(iterable $adapters)
    {
        foreach ($adapters as $adapter) {
            $providerKey = $this->normalizeProviderKey($adapter->providerKey());

            if ($providerKey === null) {
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
        $providerKey = $this->normalizeProviderKey($providerKey);

        return $providerKey !== null && isset($this->adapters[$providerKey]);
    }

    public function forProvider(string $providerKey): ExternalConnectorAdapter
    {
        $normalizedProviderKey = $this->normalizeProviderKey($providerKey);
        if ($normalizedProviderKey === null) {
            throw new \RuntimeException('No external connector adapter is registered for the requested provider.');
        }

        return $this->adapters[$normalizedProviderKey]
            ?? throw new \RuntimeException(sprintf('No external connector adapter is registered for provider "%s".', $normalizedProviderKey));
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

    private function normalizeProviderKey(string $providerKey): ?string
    {
        if (
            !mb_check_encoding($providerKey, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $providerKey) === 1
        ) {
            return null;
        }

        $providerKey = strtolower(trim($providerKey));
        if (
            $providerKey === ''
            || strlen($providerKey) > self::MAX_PROVIDER_KEY_BYTES
            || preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D', $providerKey) !== 1
        ) {
            return null;
        }

        return $providerKey;
    }
}

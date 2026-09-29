<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

final readonly class ExternalConnectorRegistry
{
    private const MAX_TARGET_KEY_BYTES = 64;
    private const TARGET_NOT_FOUND_MESSAGE = 'No enabled external connector target matches the requested key.';

    public function __construct(private ExternalConnectorTargetSource $targetSource)
    {
    }

    /** @return list<ExternalConnectorTargetDefinition> */
    public function forCapability(string $capability): array
    {
        $this->assertCapability($capability);

        return array_map(
            ExternalConnectorTargetDefinition::fromEntity(...),
            $this->targetSource->enabledFor($capability),
        );
    }

    public function target(string $capability, string $targetKey): ExternalConnectorTargetDefinition
    {
        $this->assertCapability($capability);
        $targetKey = $this->normalizeTargetKey($targetKey);
        if ($targetKey === null) {
            throw new \RuntimeException(self::TARGET_NOT_FOUND_MESSAGE);
        }

        foreach ($this->forCapability($capability) as $target) {
            if ($target->targetKey === $targetKey) {
                return $target;
            }
        }

        throw new \RuntimeException(self::TARGET_NOT_FOUND_MESSAGE);
    }

    /** @return list<ExternalConnectorTargetDefinition> */
    public function requiredFor(string $capability): array
    {
        return array_values(array_filter(
            $this->forCapability($capability),
            static fn (ExternalConnectorTargetDefinition $target): bool => $target->required,
        ));
    }

    /** @return list<ExternalConnectorTargetDefinition> */
    public function optionalFor(string $capability): array
    {
        return array_values(array_filter(
            $this->forCapability($capability),
            static fn (ExternalConnectorTargetDefinition $target): bool => !$target->required,
        ));
    }

    public function hasTargets(string $capability): bool
    {
        return $this->forCapability($capability) !== [];
    }

    private function normalizeTargetKey(string $targetKey): ?string
    {
        if (
            strlen($targetKey) > self::MAX_TARGET_KEY_BYTES
            || !mb_check_encoding($targetKey, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $targetKey) === 1
        ) {
            return null;
        }

        $targetKey = strtolower(trim($targetKey));
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D', $targetKey) !== 1) {
            return null;
        }

        return $targetKey;
    }

    private function assertCapability(string $capability): void
    {
        if (!in_array($capability, ExternalConnectorTarget::CAPABILITIES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown external connector capability "%s".', $capability));
        }
    }
}

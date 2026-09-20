<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

final readonly class ExternalConnectorRegistry
{
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
        $targetKey = strtolower(trim($targetKey));
        foreach ($this->forCapability($capability) as $target) {
            if ($target->targetKey === $targetKey) {
                return $target;
            }
        }

        throw new \RuntimeException(sprintf('No enabled target "%s" exists for capability "%s".', $targetKey, $capability));
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

    private function assertCapability(string $capability): void
    {
        if (!in_array($capability, ExternalConnectorTarget::CAPABILITIES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown external connector capability "%s".', $capability));
        }
    }
}

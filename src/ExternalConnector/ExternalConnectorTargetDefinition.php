<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

final readonly class ExternalConnectorTargetDefinition
{
    public function __construct(
        public string $capability,
        public string $targetKey,
        public string $providerKey,
        public string $displayName,
        public bool $required,
        public int $priority,
        public string $configurationReference,
    ) {
    }

    public static function fromEntity(ExternalConnectorTarget $target): self
    {
        $configurationReference = $target->getConfigurationReference();

        if (!$target->isEnabled() || $configurationReference === null) {
            throw new \LogicException('Only enabled and configured external targets can become runtime definitions.');
        }

        return new self(
            $target->getCapability(),
            $target->getTargetKey(),
            $target->getProviderKey(),
            $target->getDisplayName(),
            $target->isRequired(),
            $target->getPriority(),
            $configurationReference,
        );
    }
}

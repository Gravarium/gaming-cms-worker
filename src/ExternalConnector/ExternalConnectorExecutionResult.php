<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class ExternalConnectorExecutionResult
{
    private function __construct(
        public string $targetKey,
        public string $providerKey,
        public bool $required,
        public bool $successful,
    ) {
    }

    public static function success(ExternalConnectorTargetDefinition $target): self
    {
        return new self($target->targetKey, $target->providerKey, $target->required, true);
    }

    public static function failure(ExternalConnectorTargetDefinition $target): self
    {
        return new self($target->targetKey, $target->providerKey, $target->required, false);
    }
}

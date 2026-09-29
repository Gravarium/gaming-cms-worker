<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class ExternalConnectorExecutionResult
{
    private const MAX_KEY_LENGTH = 64;

    private function __construct(
        public string $targetKey,
        public string $providerKey,
        public bool $required,
        public bool $successful,
    ) {
    }

    public static function success(ExternalConnectorTargetDefinition $target): self
    {
        return self::fromTarget($target, true);
    }

    public static function failure(ExternalConnectorTargetDefinition $target): self
    {
        return self::fromTarget($target, false);
    }

    private static function fromTarget(ExternalConnectorTargetDefinition $target, bool $successful): self
    {
        if (!self::safeToken($target->targetKey) || !self::safeToken($target->providerKey)) {
            throw new \InvalidArgumentException('External connector execution result is invalid.');
        }

        return new self($target->targetKey, $target->providerKey, $target->required, $successful);
    }

    private static function safeToken(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= self::MAX_KEY_LENGTH
            && preg_match('//u', $value) === 1
            && preg_match('/\A[a-z0-9][a-z0-9_.-]*\z/D', $value) === 1;
    }
}

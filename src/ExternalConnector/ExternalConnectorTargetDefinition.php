<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

final readonly class ExternalConnectorTargetDefinition
{
    private const MAX_CAPABILITY_LENGTH = 40;
    private const MAX_KEY_LENGTH = 64;
    private const MAX_DISPLAY_NAME_LENGTH = 120;
    private const MAX_CONFIGURATION_REFERENCE_LENGTH = 120;
    private const MAX_PRIORITY = 10000;

    public function __construct(
        public string $capability,
        public string $targetKey,
        public string $providerKey,
        public string $displayName,
        public bool $required,
        public int $priority,
        public string $configurationReference,
    ) {
        if (!in_array($capability, ExternalConnectorTarget::CAPABILITIES, true)
            || !self::safeToken($capability, self::MAX_CAPABILITY_LENGTH)
            || !self::safeToken($targetKey, self::MAX_KEY_LENGTH)
            || !self::safeToken($providerKey, self::MAX_KEY_LENGTH)
            || !self::safeDisplayName($displayName)
            || $priority < 0
            || $priority > self::MAX_PRIORITY
            || !self::safeToken($configurationReference, self::MAX_CONFIGURATION_REFERENCE_LENGTH)
        ) {
            throw new \InvalidArgumentException('External connector target definition is invalid.');
        }
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

    private static function safeToken(string $value, int $maxLength): bool
    {
        return $value !== ''
            && strlen($value) <= $maxLength
            && preg_match('//u', $value) === 1
            && preg_match('/\A[a-z0-9][a-z0-9_.-]*\z/D', $value) === 1;
    }

    private static function safeDisplayName(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= self::MAX_DISPLAY_NAME_LENGTH
            && $value === trim($value)
            && preg_match('//u', $value) === 1
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}

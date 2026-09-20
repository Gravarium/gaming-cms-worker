<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

final readonly class ExternalConnectorPrivateConfiguration
{
    public const READY = 'ready';
    public const MISSING = 'missing';

    public function __construct(private string $configurationFile)
    {
    }

    /** @param iterable<ExternalConnectorTarget> $targets
     *  @return array<string, 'ready'|'missing'>
     */
    public function forTargets(iterable $targets): array
    {
        $entries = $this->entries();
        $statuses = [];

        foreach ($targets as $target) {
            if (in_array($target->getCapability(), [ExternalConnectorTarget::CAPABILITY_BACKUP, ExternalConnectorTarget::CAPABILITY_MEDIA], true)) {
                continue;
            }

            $reference = $target->getConfigurationReference();
            if ($this->isBuiltIn($target, $reference)) {
                $statuses[$target->getTargetKey()] = self::READY;
                continue;
            }

            $entry = $reference === null ? null : ($entries[$reference] ?? null);
            $statuses[$target->getTargetKey()] = is_array($entry)
                && $entry['enabled'] === true
                && $entry['capability'] === $target->getCapability()
                && $entry['provider'] === $target->getProviderKey()
                && $entry['configuration'] !== []
                    ? self::READY
                    : self::MISSING;
        }

        return $statuses;
    }

    /** @return array<string, mixed> */
    public function forTarget(ExternalConnectorTargetDefinition $target): array
    {
        $entries = $this->entries();
        $entry = $entries[$target->configurationReference] ?? null;
        if (
            !is_array($entry)
            || $entry['enabled'] !== true
            || $entry['capability'] !== $target->capability
            || $entry['provider'] !== $target->providerKey
            || $entry['configuration'] === []
        ) {
            throw new \RuntimeException('Private connector configuration is not ready.');
        }

        return $entry['configuration'];
    }

    /** @return array<string, array{capability: string, provider: string, enabled: bool, configuration: array<string, mixed>}> */
    private function entries(): array
    {
        if (
            !str_starts_with($this->configurationFile, '/')
            || !is_file($this->configurationFile)
            || is_link($this->configurationFile)
            || !is_readable($this->configurationFile)
        ) {
            return [];
        }

        $mode = @fileperms($this->configurationFile);
        $content = @file_get_contents($this->configurationFile);
        if ($mode === false || ($mode & 0077) !== 0 || $content === false) {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded) || count($decoded) !== 1 || !isset($decoded['targets']) || !is_array($decoded['targets'])) {
            return [];
        }

        $entries = [];
        foreach ($decoded['targets'] as $entry) {
            if (
                !is_array($entry)
                || count($entry) !== 5
                || array_diff(['reference', 'capability', 'provider', 'enabled', 'configuration'], array_keys($entry)) !== []
                || !is_string($entry['reference'])
                || preg_match('/^[a-z0-9][a-z0-9_.-]{0,119}$/', $entry['reference']) !== 1
                || isset($entries[$entry['reference']])
                || !is_string($entry['capability'])
                || !in_array($entry['capability'], ExternalConnectorTarget::CAPABILITIES, true)
                || !is_string($entry['provider'])
                || preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $entry['provider']) !== 1
                || !is_bool($entry['enabled'])
                || !is_array($entry['configuration'])
            ) {
                return [];
            }

            $entries[$entry['reference']] = [
                'capability' => $entry['capability'],
                'provider' => $entry['provider'],
                'enabled' => $entry['enabled'],
                'configuration' => $entry['configuration'],
            ];
        }

        return $entries;
    }

    private function isBuiltIn(ExternalConnectorTarget $target, ?string $reference): bool
    {
        return ($target->getCapability() === ExternalConnectorTarget::CAPABILITY_MAIL
                && $target->getProviderKey() === SymfonyMailerConnectorAdapter::PROVIDER_KEY
                && $reference === SymfonyMailerConnectorAdapter::CONFIGURATION_REFERENCE)
            || ($target->getCapability() === ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS
                && $target->getProviderKey() === DiscordGuildNotificationConnectorAdapter::PROVIDER_KEY
                && $reference === DiscordGuildNotificationConnectorAdapter::CONFIGURATION_REFERENCE);
    }
}

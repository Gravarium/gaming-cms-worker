<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

final readonly class DevelopmentExternalConnectorPrivateConfiguration implements ExternalConnectorPrivateConfiguration
{
    public function forTargets(iterable $targets): array
    {
        $statuses = [];
        foreach ($targets as $target) {
            if (in_array($target->getCapability(), [ExternalConnectorTarget::CAPABILITY_BACKUP, ExternalConnectorTarget::CAPABILITY_MEDIA], true)) {
                continue;
            }

            $reference = $target->getConfigurationReference();
            $builtIn = ($target->getCapability() === ExternalConnectorTarget::CAPABILITY_MAIL
                    && $target->getProviderKey() === SymfonyMailerConnectorAdapter::PROVIDER_KEY
                    && $reference === SymfonyMailerConnectorAdapter::CONFIGURATION_REFERENCE)
                || ($target->getCapability() === ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS
                    && $target->getProviderKey() === DiscordGuildNotificationConnectorAdapter::PROVIDER_KEY
                    && $reference === DiscordGuildNotificationConnectorAdapter::CONFIGURATION_REFERENCE);

            $statuses[$target->getTargetKey()] = $builtIn ? self::READY : self::MISSING;
        }

        return $statuses;
    }

    public function forTarget(ExternalConnectorTargetDefinition $target): array
    {
        throw new \RuntimeException('Private connector configuration is unavailable outside the production runtime.');
    }
}

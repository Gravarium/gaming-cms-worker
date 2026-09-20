<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

final readonly class MediaTargetConfigurationStatus
{
    public const READY = 'ready';
    public const MISSING = 'missing';
    public const NOT_APPLICABLE = 'not_applicable';

    public function __construct(private S3MediaTargetConfigurationProvider $s3Configurations)
    {
    }

    /**
     * @param iterable<ExternalConnectorTarget> $targets
     * @return array<string, string>
     */
    public function forTargets(iterable $targets): array
    {
        $statuses = [];
        foreach ($targets as $target) {
            if ($target->getCapability() !== ExternalConnectorTarget::CAPABILITY_MEDIA) {
                continue;
            }

            if ($target->getProviderKey() !== S3CompatibleMediaConnectorAdapter::PROVIDER_KEY) {
                $statuses[$target->getTargetKey()] = self::NOT_APPLICABLE;
                continue;
            }

            $reference = $target->getConfigurationReference();
            if ($reference === null) {
                $statuses[$target->getTargetKey()] = self::MISSING;
                continue;
            }

            try {
                $this->s3Configurations->forReference($reference);
                $statuses[$target->getTargetKey()] = self::READY;
            } catch (\Throwable) {
                // Only readiness is exposed. Private paths, endpoints and provider errors remain hidden.
                $statuses[$target->getTargetKey()] = self::MISSING;
            }
        }

        return $statuses;
    }
}

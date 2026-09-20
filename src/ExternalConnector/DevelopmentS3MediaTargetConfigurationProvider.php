<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class DevelopmentS3MediaTargetConfigurationProvider implements S3MediaTargetConfigurationProvider
{
    public function forReference(string $reference): array
    {
        throw new \RuntimeException('Private S3 media configuration is unavailable outside the production runtime.');
    }
}

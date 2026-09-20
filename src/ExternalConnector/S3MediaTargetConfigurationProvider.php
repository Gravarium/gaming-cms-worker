<?php

declare(strict_types=1);

namespace App\ExternalConnector;

interface S3MediaTargetConfigurationProvider
{
    /** @return array{endpoint: string, region: string, bucket: string, access_key: string, secret_key: string, public_url: string} */
    public function forReference(string $reference): array;
}

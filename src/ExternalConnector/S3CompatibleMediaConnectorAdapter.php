<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\Service\S3ObjectStorage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class S3CompatibleMediaConnectorAdapter implements ExternalMediaConnectorAdapter, ExternalConnectorHealthCheckAdapter
{
    public const PROVIDER_KEY = 's3-compatible';

    public function __construct(
        private HttpClientInterface $httpClient,
        private S3MediaTargetConfigurationProvider $configurations,
    ) {
    }

    public function providerKey(): string
    {
        return self::PROVIDER_KEY;
    }

    public function supports(string $capability): bool
    {
        return $capability === ExternalConnectorTarget::CAPABILITY_MEDIA;
    }

    public function store(ExternalConnectorTargetDefinition $target, ExternalMediaUpload $upload): ExternalMediaObject
    {
        $storage = $this->storageFor($target);
        $location = $storage->upload($upload->objectKey, $upload->localPath, $upload->mimeType);
        $size = filesize($upload->localPath);

        return new ExternalMediaObject(
            $upload->objectKey,
            $location,
            $size === false ? null : $size,
        );
    }

    public function check(ExternalConnectorTargetDefinition $target): void
    {
        $this->storageFor($target)->checkConnection();
    }

    public function delete(ExternalConnectorTargetDefinition $target, string $objectKey): void
    {
        $this->storageFor($target)->delete($objectKey);
    }

    private function storageFor(ExternalConnectorTargetDefinition $target): S3ObjectStorage
    {
        $configuration = $this->configurations->forReference($target->configurationReference);

        return new S3ObjectStorage(
            $this->httpClient,
            $configuration['endpoint'],
            $configuration['region'],
            $configuration['bucket'],
            $configuration['access_key'],
            $configuration['secret_key'],
            $configuration['public_url'],
        );
    }
}

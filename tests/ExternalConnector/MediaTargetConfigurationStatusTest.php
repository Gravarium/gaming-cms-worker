<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\MediaTargetConfigurationStatus;
use App\ExternalConnector\S3CompatibleMediaConnectorAdapter;
use App\ExternalConnector\S3MediaTargetConfigurationProvider;
use PHPUnit\Framework\TestCase;

final class MediaTargetConfigurationStatusTest extends TestCase
{
    public function testReportsOnlySanitizedReadinessForMediaTargets(): void
    {
        $provider = new class implements S3MediaTargetConfigurationProvider {
            public function forReference(string $reference): array
            {
                if ($reference !== 'media.ready') {
                    throw new \RuntimeException('Unavailable.');
                }

                return [
                    'endpoint' => 'https://objects.example.invalid',
                    'region' => 'eu-test-1',
                    'bucket' => 'media',
                    'access_key' => 'test-access',
                    'secret_key' => 'test-secret',
                    'public_url' => '',
                ];
            }
        };
        $resolver = new MediaTargetConfigurationStatus($provider);

        $statuses = $resolver->forTargets([
            $this->target('ready', S3CompatibleMediaConnectorAdapter::PROVIDER_KEY, 'media.ready'),
            $this->target('missing', S3CompatibleMediaConnectorAdapter::PROVIDER_KEY, 'media.missing'),
            $this->target('other', 'future-provider', 'media.other'),
            (new ExternalConnectorTarget())
                ->setCapability(ExternalConnectorTarget::CAPABILITY_BACKUP)
                ->setTargetKey('backup')
                ->setProviderKey('restic')
                ->setDisplayName('Backup'),
        ]);

        self::assertSame([
            'ready' => MediaTargetConfigurationStatus::READY,
            'missing' => MediaTargetConfigurationStatus::MISSING,
            'other' => MediaTargetConfigurationStatus::NOT_APPLICABLE,
        ], $statuses);
        self::assertStringNotContainsString('test-secret', json_encode($statuses, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('backup', $statuses);
    }

    private function target(string $key, string $provider, string $reference): ExternalConnectorTarget
    {
        return (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_MEDIA)
            ->setTargetKey($key)
            ->setProviderKey($provider)
            ->setDisplayName($key)
            ->setConfigurationReference($reference);
    }
}

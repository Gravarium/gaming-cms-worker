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
    private string $configurationFile;

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'media-status-');
        if ($file === false) {
            throw new \RuntimeException('Temporary configuration could not be created.');
        }
        $this->configurationFile = $file;

        file_put_contents($file, json_encode([
            'media.ready' => [
                'endpoint' => 'https://objects.example.invalid',
                'region' => 'eu-test-1',
                'bucket' => 'media',
                'access_key' => 'access',
                'secret_key' => 'secret',
                'public_url' => '',
            ],
        ], JSON_THROW_ON_ERROR));
        chmod($file, 0600);
    }

    protected function tearDown(): void
    {
        @unlink($this->configurationFile);
    }

    public function testReportsOnlySanitizedReadinessForMediaTargets(): void
    {
        $resolver = new MediaTargetConfigurationStatus(
            new S3MediaTargetConfigurationProvider($this->configurationFile),
        );

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
        self::assertStringNotContainsString('secret', json_encode($statuses, JSON_THROW_ON_ERROR));
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

<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\ExternalConnectorPrivateConfiguration;
use PHPUnit\Framework\TestCase;

final class ExternalConnectorPrivateConfigurationTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'connector-config-');
        self::assertIsString($this->file);
        chmod($this->file, 0600);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testReportsOnlyMatchingEnabledCompleteTargetAsReady(): void
    {
        file_put_contents($this->file, json_encode(['targets' => [[
            'reference' => 'analytics.matomo',
            'capability' => 'analytics',
            'provider' => 'matomo',
            'enabled' => true,
            'configuration' => ['private_url' => 'https://private.invalid', 'token' => 'secret'],
        ]]], JSON_THROW_ON_ERROR));

        $target = (new ExternalConnectorTarget())
            ->setCapability('analytics')
            ->setTargetKey('analytics-matomo')
            ->setProviderKey('matomo')
            ->setDisplayName('Matomo')
            ->setConfigurationReference('analytics.matomo');

        self::assertSame(
            ['analytics-matomo' => ExternalConnectorPrivateConfiguration::READY],
            (new ExternalConnectorPrivateConfiguration($this->file))->forTargets([$target]),
        );
    }

    public function testFailsClosedForLoosePermissions(): void
    {
        file_put_contents($this->file, '{"targets":[]}');
        chmod($this->file, 0640);
        clearstatcache(true, $this->file);
        $target = (new ExternalConnectorTarget())
            ->setCapability('cdn')
            ->setTargetKey('cdn-cloudflare')
            ->setProviderKey('cloudflare')
            ->setDisplayName('Cloudflare')
            ->setConfigurationReference('cdn.cloudflare');

        self::assertSame(
            ['cdn-cloudflare' => ExternalConnectorPrivateConfiguration::MISSING],
            (new ExternalConnectorPrivateConfiguration($this->file))->forTargets([$target]),
        );
    }

    public function testRecognizesExistingBuiltInTransportsWithoutReadingSecrets(): void
    {
        $mail = (new ExternalConnectorTarget())
            ->setCapability('mail')
            ->setTargetKey('mail-default')
            ->setProviderKey('symfony-mailer')
            ->setDisplayName('Mailer')
            ->setConfigurationReference('mailer.default');

        self::assertSame(
            ['mail-default' => ExternalConnectorPrivateConfiguration::READY],
            (new ExternalConnectorPrivateConfiguration('/missing'))->forTargets([$mail]),
        );
    }
}

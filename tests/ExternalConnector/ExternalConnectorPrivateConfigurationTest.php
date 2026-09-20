<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\DevelopmentExternalConnectorPrivateConfiguration;
use App\ExternalConnector\ExternalConnectorPrivateConfiguration;
use App\ExternalConnector\ExternalConnectorTargetDefinition;
use PHPUnit\Framework\TestCase;

final class ExternalConnectorPrivateConfigurationTest extends TestCase
{
    public function testDevelopmentRuntimeFailsClosedForPrivateTargets(): void
    {
        $target = (new ExternalConnectorTarget())
            ->setCapability('analytics')
            ->setTargetKey('analytics-matomo')
            ->setProviderKey('matomo')
            ->setDisplayName('Matomo')
            ->setConfigurationReference('analytics.matomo');

        self::assertSame(
            ['analytics-matomo' => ExternalConnectorPrivateConfiguration::MISSING],
            (new DevelopmentExternalConnectorPrivateConfiguration())->forTargets([$target]),
        );
    }

    public function testDevelopmentRuntimeRecognizesBuiltInTransportWithoutPrivateConfiguration(): void
    {
        $mail = (new ExternalConnectorTarget())
            ->setCapability('mail')
            ->setTargetKey('mail-default')
            ->setProviderKey('symfony-mailer')
            ->setDisplayName('Mailer')
            ->setConfigurationReference('mailer.default');

        self::assertSame(
            ['mail-default' => ExternalConnectorPrivateConfiguration::READY],
            (new DevelopmentExternalConnectorPrivateConfiguration())->forTargets([$mail]),
        );
    }

    public function testDevelopmentRuntimeCannotResolvePrivateValues(): void
    {
        $provider = new DevelopmentExternalConnectorPrivateConfiguration();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unavailable outside the production runtime');
        $provider->forTarget(new ExternalConnectorTargetDefinition(
            'analytics',
            'matomo',
            'matomo',
            'Matomo',
            true,
            10,
            'analytics.matomo',
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\ExternalConnectorTargetDefinition;
use PHPUnit\Framework\TestCase;

final class ExternalConnectorTargetDefinitionTest extends TestCase
{
    public function testAcceptsBoundedDefinitionAndPreservesValues(): void
    {
        $definition = new ExternalConnectorTargetDefinition(
            ExternalConnectorTarget::CAPABILITY_MEDIA,
            'primary-media',
            's3-compatible',
            'Primary Medien',
            true,
            100,
            'media.primary',
        );

        self::assertSame(ExternalConnectorTarget::CAPABILITY_MEDIA, $definition->capability);
        self::assertSame('primary-media', $definition->targetKey);
        self::assertSame('s3-compatible', $definition->providerKey);
        self::assertSame('Primary Medien', $definition->displayName);
        self::assertTrue($definition->required);
        self::assertSame(100, $definition->priority);
        self::assertSame('media.primary', $definition->configurationReference);
    }

    public function testFromEntityRequiresEnabledConfiguredTargets(): void
    {
        $target = (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_MAIL)
            ->setTargetKey('mail-default')
            ->setProviderKey('symfony-mailer')
            ->setDisplayName('Mailer')
            ->setPriority(10)
            ->setConfigurationReference('mailer.default')
            ->setEnabled(true);

        $definition = ExternalConnectorTargetDefinition::fromEntity($target);

        self::assertSame('mail-default', $definition->targetKey);
        self::assertSame('mailer.default', $definition->configurationReference);
    }

    public function testRejectsUnknownCapability(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalConnectorTargetDefinition(
            'unknown',
            'target',
            'provider',
            'Target',
            true,
            10,
            'connector.target',
        );
    }

    public function testRejectsUnsafeTargetKeyAndConfigurationReference(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalConnectorTargetDefinition(
            ExternalConnectorTarget::CAPABILITY_MEDIA,
            'target/key',
            'provider',
            'Target',
            true,
            10,
            'connector.target',
        );
    }

    public function testRejectsMalformedUtf8AndControlCharactersInDisplayName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalConnectorTargetDefinition(
            ExternalConnectorTarget::CAPABILITY_MEDIA,
            'target',
            'provider',
            "Target \xC3\x28",
            true,
            10,
            'connector.target',
        );
    }

    public function testRejectsControlCharactersInDisplayName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalConnectorTargetDefinition(
            ExternalConnectorTarget::CAPABILITY_MEDIA,
            'target',
            'provider',
            "Target\nName",
            true,
            10,
            'connector.target',
        );
    }

    public function testRejectsOversizedDisplayName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalConnectorTargetDefinition(
            ExternalConnectorTarget::CAPABILITY_MEDIA,
            'target',
            'provider',
            str_repeat('a', 121),
            true,
            10,
            'connector.target',
        );
    }

    public function testRejectsPriorityOutsideBoundedRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalConnectorTargetDefinition(
            ExternalConnectorTarget::CAPABILITY_MEDIA,
            'target',
            'provider',
            'Target',
            true,
            -1,
            'connector.target',
        );
    }

    public function testRejectsOversizedPriority(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalConnectorTargetDefinition(
            ExternalConnectorTarget::CAPABILITY_MEDIA,
            'target',
            'provider',
            'Target',
            true,
            10001,
            'connector.target',
        );
    }

    public function testFromEntityRejectsDisabledTargets(): void
    {
        $target = (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_MAIL)
            ->setTargetKey('mail-default')
            ->setProviderKey('symfony-mailer')
            ->setDisplayName('Mailer')
            ->setConfigurationReference('mailer.default');

        $this->expectException(\LogicException::class);

        ExternalConnectorTargetDefinition::fromEntity($target);
    }

    public function testFromEntityRejectsUnconfiguredTargets(): void
    {
        $target = (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_MAIL)
            ->setTargetKey('mail-default')
            ->setProviderKey('symfony-mailer')
            ->setDisplayName('Mailer')
            ->setEnabled(true);

        $this->expectException(\LogicException::class);

        ExternalConnectorTargetDefinition::fromEntity($target);
    }
}

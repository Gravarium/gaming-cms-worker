<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\ExternalConnectorRegistry;
use App\ExternalConnector\ExternalConnectorTargetSource;
use PHPUnit\Framework\TestCase;

final class ExternalConnectorRegistryTest extends TestCase
{
    public function testExposesProviderNeutralRuntimeDefinitions(): void
    {
        $required = $this->target('backup-primary', 'pcloud', true, 10);
        $optional = $this->target('backup-secondary', 'dropbox', false, 20);
        $registry = new ExternalConnectorRegistry($this->source([$required, $optional]));

        $targets = $registry->forCapability(ExternalConnectorTarget::CAPABILITY_BACKUP);

        self::assertCount(2, $targets);
        self::assertSame('backup-primary', $targets[0]->targetKey);
        self::assertSame('pcloud', $targets[0]->providerKey);
        self::assertSame('backup.backup-primary', $targets[0]->configurationReference);
        self::assertCount(1, $registry->requiredFor(ExternalConnectorTarget::CAPABILITY_BACKUP));
        self::assertSame('backup-secondary', $registry->optionalFor(ExternalConnectorTarget::CAPABILITY_BACKUP)[0]->targetKey);
        self::assertTrue($registry->hasTargets(ExternalConnectorTarget::CAPABILITY_BACKUP));
        self::assertFalse($registry->hasTargets(ExternalConnectorTarget::CAPABILITY_MEDIA));
    }

    public function testRejectsUnknownCapabilitiesBeforeQueryingTargets(): void
    {
        $registry = new ExternalConnectorRegistry($this->source([]));

        $this->expectException(\InvalidArgumentException::class);
        $registry->forCapability('everything');
    }

    /** @param list<ExternalConnectorTarget> $targets */
    private function source(array $targets): ExternalConnectorTargetSource
    {
        return new class($targets) implements ExternalConnectorTargetSource {
            /** @param list<ExternalConnectorTarget> $targets */
            public function __construct(private readonly array $targets)
            {
            }

            public function enabledFor(string $capability): array
            {
                return array_values(array_filter(
                    $this->targets,
                    static fn (ExternalConnectorTarget $target): bool => $target->getCapability() === $capability,
                ));
            }
        };
    }

    private function target(string $targetKey, string $providerKey, bool $required, int $priority): ExternalConnectorTarget
    {
        return (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_BACKUP)
            ->setTargetKey($targetKey)
            ->setProviderKey($providerKey)
            ->setDisplayName($targetKey)
            ->setConfigurationReference('backup.'.$targetKey)
            ->setPriority($priority)
            ->setRequired($required)
            ->setEnabled(true);
    }
}

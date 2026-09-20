<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\BackupTargetSelectionExporter;
use App\ExternalConnector\ExternalConnectorRegistry;
use App\ExternalConnector\ExternalConnectorTargetSource;
use PHPUnit\Framework\TestCase;

final class BackupTargetSelectionExporterTest extends TestCase
{
    public function testExportsOnlySafeRuntimeSelectionFields(): void
    {
        $registry = new ExternalConnectorRegistry($this->source([
            $this->target('primary', 'private.primary', true),
            $this->target('secondary', 'private.secondary', false),
        ]));

        $content = (new BackupTargetSelectionExporter($registry))->export();

        self::assertSame(
            "# configuration_reference|target_key|required\nprivate.primary|primary|1\nprivate.secondary|secondary|0\n",
            $content,
        );
        self::assertStringNotContainsString('password', $content);
        self::assertStringNotContainsString('repository', $content);
    }

    public function testRefusesAnEmptyBackupSelection(): void
    {
        $this->expectException(\RuntimeException::class);

        (new BackupTargetSelectionExporter($this->registry([])))->export();
    }

    public function testAllowsHeaderOnlySelectionForAutomaticSynchronization(): void
    {
        self::assertSame(
            "# configuration_reference|target_key|required\n",
            (new BackupTargetSelectionExporter($this->registry([])))->export(true),
        );
    }

    /** @param list<ExternalConnectorTarget> $targets */
    private function registry(array $targets): ExternalConnectorRegistry
    {
        return new ExternalConnectorRegistry($this->source($targets));
    }

    /** @param list<ExternalConnectorTarget> $targets */
    private function source(array $targets): ExternalConnectorTargetSource
    {
        return new class($targets) implements ExternalConnectorTargetSource {
            /** @param list<ExternalConnectorTarget> $targets */
            public function __construct(private readonly array $targets) {}

            public function enabledFor(string $capability): array
            {
                return array_values(array_filter(
                    $this->targets,
                    static fn (ExternalConnectorTarget $target): bool => $target->getCapability() === $capability,
                ));
            }
        };
    }

    private function target(string $key, string $reference, bool $required): ExternalConnectorTarget
    {
        return (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_BACKUP)
            ->setTargetKey($key)
            ->setProviderKey('restic')
            ->setDisplayName($key)
            ->setConfigurationReference($reference)
            ->setRequired($required)
            ->setEnabled(true);
    }
}

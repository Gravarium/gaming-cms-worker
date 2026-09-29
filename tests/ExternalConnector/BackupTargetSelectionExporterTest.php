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

    public function testRejectsDelimiterControlAndMalformedEncodingWithoutEchoingInput(): void
    {
        $invalid = [
            ['primary|injected', 'private.primary'],
            ["secondary\ninjected", 'private.secondary'],
            ['secondary', 'private.secondary|injected'],
            ['secondary', "private.secondary\r\ninjected"],
            ['secondary', "private.\0injected"],
            ['secondary', "private.\xFF"],
        ];

        foreach ($invalid as [$key, $reference]) {
            $this->assertExportRejects($this->target($key, $reference, false));
        }
    }

    public function testRejectsOverlongExportFields(): void
    {
        $this->assertExportRejects($this->target(str_repeat('a', 65), 'private.secondary', false));
        $this->assertExportRejects($this->target('secondary', str_repeat('a', 121), false));
    }

    public function testAcceptsExportTokensAtTheirByteLimits(): void
    {
        $key = str_repeat('a', 64);
        $reference = str_repeat('b', 120);

        self::assertSame(
            "# configuration_reference|target_key|required\n".$reference.'|'.$key."|1\n",
            (new BackupTargetSelectionExporter($this->registry([
                $this->target($key, $reference, true),
            ])))->export(),
        );
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

    private function assertExportRejects(ExternalConnectorTarget $invalidTarget): void
    {
        $registry = $this->registry([
            $this->target('primary', 'private.primary', true),
            $invalidTarget,
        ]);

        try {
            (new BackupTargetSelectionExporter($registry))->export();
        } catch (\RuntimeException $exception) {
            self::assertSame('Backup target selection contains invalid data.', $exception->getMessage());
            return;
        }

        self::fail('Expected unsafe persisted backup selection values to be rejected.');
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

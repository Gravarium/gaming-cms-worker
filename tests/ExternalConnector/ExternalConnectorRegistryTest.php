<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\ExternalConnectorRegistry;
use App\ExternalConnector\ExternalConnectorTargetSource;
use PHPUnit\Framework\TestCase;

final class ExternalConnectorRegistryTest extends TestCase
{
    private const TARGET_NOT_FOUND_MESSAGE = 'No enabled external connector target matches the requested key.';

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

    public function testResolvesTargetUsingTrimmedCaseInsensitiveLookupKey(): void
    {
        $target = $this->target('backup-primary', 'pcloud', true, 10);
        $registry = new ExternalConnectorRegistry($this->source([$target]));

        self::assertSame(
            'backup-primary',
            $registry->target(ExternalConnectorTarget::CAPABILITY_BACKUP, ' BACKUP-PRIMARY ')->targetKey,
        );
    }

    public function testRejectsMalformedAndOverlongLookupKeysBeforeQueryingTargets(): void
    {
        $source = $this->createMock(ExternalConnectorTargetSource::class);
        $source->expects(self::never())->method('enabledFor');
        $registry = new ExternalConnectorRegistry($source);

        foreach ([
            '',
            str_repeat('a', 65),
            "backup-primary\r\nBcc: attacker@example.invalid",
            "backup-primary\x00",
            "backup-primary\xFF",
        ] as $lookupKey) {
            try {
                $registry->target(ExternalConnectorTarget::CAPABILITY_BACKUP, $lookupKey);
            } catch (\RuntimeException $exception) {
                self::assertSame(self::TARGET_NOT_FOUND_MESSAGE, $exception->getMessage());

                continue;
            }

            self::fail('The unsafe target lookup key was accepted.');
        }
    }

    public function testDoesNotEchoUnknownLookupKeysInErrors(): void
    {
        $registry = new ExternalConnectorRegistry($this->source([]));

        try {
            $registry->target(ExternalConnectorTarget::CAPABILITY_BACKUP, 'unknown-target');
        } catch (\RuntimeException $exception) {
            self::assertSame(self::TARGET_NOT_FOUND_MESSAGE, $exception->getMessage());

            return;
        }

        self::fail('The unknown target lookup unexpectedly succeeded.');
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

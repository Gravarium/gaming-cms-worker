<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\ExternalConnectorAdapter;
use App\ExternalConnector\ExternalConnectorAdapterRegistry;
use App\ExternalConnector\ExternalConnectorTargetDefinition;
use PHPUnit\Framework\TestCase;

final class ExternalConnectorAdapterRegistryTest extends TestCase
{
    public function testResolvesAnAdapterForASupportedTarget(): void
    {
        $adapter = $this->adapter('pcloud', [ExternalConnectorTarget::CAPABILITY_BACKUP]);
        $registry = new ExternalConnectorAdapterRegistry([$adapter]);

        self::assertSame(['pcloud'], $registry->providerKeys());
        self::assertTrue($registry->has(' PCLOUD '));
        self::assertSame($adapter, $registry->forTarget($this->target('pcloud', ExternalConnectorTarget::CAPABILITY_BACKUP)));
    }

    public function testRejectsMissingProviderAdapters(): void
    {
        $registry = new ExternalConnectorAdapterRegistry([]);

        $this->expectException(\RuntimeException::class);
        $registry->forTarget($this->target('dropbox', ExternalConnectorTarget::CAPABILITY_BACKUP));
    }

    public function testRejectsUnsupportedCapabilities(): void
    {
        $registry = new ExternalConnectorAdapterRegistry([
            $this->adapter('pcloud', [ExternalConnectorTarget::CAPABILITY_BACKUP]),
        ]);

        $this->expectException(\RuntimeException::class);
        $registry->forTarget($this->target('pcloud', ExternalConnectorTarget::CAPABILITY_MEDIA));
    }

    public function testRejectsDuplicateProviderAdapters(): void
    {
        $this->expectException(\LogicException::class);

        new ExternalConnectorAdapterRegistry([
            $this->adapter('box', [ExternalConnectorTarget::CAPABILITY_BACKUP]),
            $this->adapter('BOX', [ExternalConnectorTarget::CAPABILITY_MEDIA]),
        ]);
    }

    public function testRejectsUnsafeOrOverlongProviderKeys(): void
    {
        foreach (['', 'bad key', "pcloud\x00", "pcloud\r\n", "pcloud\xFF", str_repeat('a', 65)] as $providerKey) {
            try {
                new ExternalConnectorAdapterRegistry([
                    $this->adapter($providerKey, [ExternalConnectorTarget::CAPABILITY_BACKUP]),
                ]);
            } catch (\LogicException) {
                self::addToAssertionCount(1);

                continue;
            }

            self::fail('The unsafe provider key was accepted.');
        }
    }

    public function testFailsClosedForUnsafeProviderLookups(): void
    {
        $registry = new ExternalConnectorAdapterRegistry([
            $this->adapter('pcloud', [ExternalConnectorTarget::CAPABILITY_BACKUP]),
        ]);

        self::assertFalse($registry->has(str_repeat('a', 65)));
        self::assertFalse($registry->has("pcloud\x00"));

        try {
            $registry->forProvider("pcloud\xFF");
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);

            return;
        }

        self::fail('The unsafe provider lookup was accepted.');
    }

    /** @param list<string> $capabilities */
    private function adapter(string $providerKey, array $capabilities): ExternalConnectorAdapter
    {
        return new class($providerKey, $capabilities) implements ExternalConnectorAdapter {
            /** @param list<string> $capabilities */
            public function __construct(
                private readonly string $key,
                private readonly array $capabilities,
            ) {
            }

            public function providerKey(): string
            {
                return $this->key;
            }

            public function supports(string $capability): bool
            {
                return in_array($capability, $this->capabilities, true);
            }
        };
    }

    private function target(string $providerKey, string $capability): ExternalConnectorTargetDefinition
    {
        return new ExternalConnectorTargetDefinition(
            $capability,
            'target-1',
            $providerKey,
            'Target 1',
            true,
            10,
            'connector.target-1',
        );
    }
}

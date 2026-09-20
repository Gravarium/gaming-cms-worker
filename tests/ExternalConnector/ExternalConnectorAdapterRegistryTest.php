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

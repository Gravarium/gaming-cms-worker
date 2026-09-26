<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\ExternalConnectorAdapter;
use App\ExternalConnector\ExternalConnectorAdapterRegistry;
use App\ExternalConnector\ExternalConnectorExecutionSummary;
use App\ExternalConnector\ExternalConnectorExecutor;
use App\ExternalConnector\ExternalConnectorRegistry;
use App\ExternalConnector\ExternalConnectorTargetDefinition;
use App\ExternalConnector\ExternalConnectorTargetSource;
use PHPUnit\Framework\TestCase;

final class ExternalConnectorExecutorBoundaryTest extends TestCase
{
    public function testBoundedTargetFanoutStillExecutesAndAggregatesResults(): void
    {
        $executed = [];
        $executor = $this->executor($this->targets(2));

        $summary = $executor->execute(
            ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS,
            static function (ExternalConnectorAdapter $adapter, ExternalConnectorTargetDefinition $target) use (&$executed): void {
                $executed[] = $target->targetKey;
            },
        );

        self::assertSame(['target-0', 'target-1'], $executed);
        self::assertSame(ExternalConnectorExecutionSummary::STATUS_HEALTHY, $summary->status());
        self::assertSame(2, $summary->successfulCount());
    }

    public function testProviderFailuresRemainIsolatedForBoundedFanout(): void
    {
        $executor = $this->executor($this->targets(2));

        $summary = $executor->execute(
            ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS,
            static function (ExternalConnectorAdapter $adapter, ExternalConnectorTargetDefinition $target): void {
                if ($target->targetKey === 'target-1') {
                    throw new \RuntimeException('provider failure');
                }
            },
        );

        self::assertSame(ExternalConnectorExecutionSummary::STATUS_DEGRADED, $summary->status());
        self::assertSame(1, $summary->successfulCount());
    }

    public function testRejectsFanoutAboveBoundBeforeInvokingAdapters(): void
    {
        $invocations = 0;
        $executor = $this->executor($this->targets(65));

        $this->expectException(\DomainException::class);

        $executor->execute(
            ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS,
            static function (ExternalConnectorAdapter $adapter, ExternalConnectorTargetDefinition $target) use (&$invocations): void {
                ++$invocations;
            },
        );

        self::assertSame(0, $invocations);
    }

    /**
     * @param list<ExternalConnectorTarget> $targets
     */
    private function executor(array $targets): ExternalConnectorExecutor
    {
        $source = new class($targets) implements ExternalConnectorTargetSource {
            /** @param list<ExternalConnectorTarget> $targets */
            public function __construct(private readonly array $targets)
            {
            }

            /** @return list<ExternalConnectorTarget> */
            public function enabledFor(string $capability): array
            {
                return array_values(array_filter(
                    $this->targets,
                    static fn (ExternalConnectorTarget $target): bool => $target->getCapability() === $capability,
                ));
            }
        };

        $adapter = new class implements ExternalConnectorAdapter {
            public function providerKey(): string
            {
                return 'provider';
            }

            public function supports(string $capability): bool
            {
                return true;
            }
        };

        return new ExternalConnectorExecutor(
            new ExternalConnectorRegistry($source),
            new ExternalConnectorAdapterRegistry([$adapter]),
        );
    }

    /** @return list<ExternalConnectorTarget> */
    private function targets(int $count): array
    {
        $targets = [];
        for ($index = 0; $index < $count; ++$index) {
            $targets[] = (new ExternalConnectorTarget())
                ->setCapability(ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS)
                ->setTargetKey('target-'.$index)
                ->setProviderKey('provider')
                ->setDisplayName('Target '.$index)
                ->setConfigurationReference('connector.target-'.$index)
                ->setRequired(false)
                ->setEnabled(true);
        }

        return $targets;
    }
}

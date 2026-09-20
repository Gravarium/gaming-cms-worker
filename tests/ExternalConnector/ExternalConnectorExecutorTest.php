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

final class ExternalConnectorExecutorTest extends TestCase
{
    public function testRunsEveryTargetAndReportsOptionalFailureAsDegraded(): void
    {
        $executor = $this->executor([
            $this->target('primary', 'provider-a', true, 10),
            $this->target('secondary', 'provider-b', false, 20),
            $this->target('third', 'provider-c', false, 30),
        ]);
        $attempted = [];

        $summary = $executor->execute(ExternalConnectorTarget::CAPABILITY_MAIL, function (
            ExternalConnectorAdapter $adapter,
            ExternalConnectorTargetDefinition $target,
        ) use (&$attempted): void {
            $attempted[] = $target->targetKey;
            if ($target->targetKey === 'secondary') {
                throw new \RuntimeException('secret provider response');
            }
        });

        self::assertSame(['primary', 'secondary', 'third'], $attempted);
        self::assertSame(ExternalConnectorExecutionSummary::STATUS_DEGRADED, $summary->status());
        self::assertSame(2, $summary->successfulCount());
        self::assertFalse($summary->results[1]->successful);
    }

    public function testRequiredFailureMakesTheSummaryFailedWithoutStoppingOtherTargets(): void
    {
        $executor = $this->executor([
            $this->target('required', 'provider-a', true, 10, ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS),
            $this->target('later', 'provider-b', false, 20, ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS),
        ]);
        $attempted = [];

        $summary = $executor->execute(ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS, function (
            ExternalConnectorAdapter $adapter,
            ExternalConnectorTargetDefinition $target,
        ) use (&$attempted): void {
            $attempted[] = $target->targetKey;
            if ($target->required) {
                throw new \RuntimeException('failure');
            }
        });

        self::assertSame(['required', 'later'], $attempted);
        self::assertSame(ExternalConnectorExecutionSummary::STATUS_FAILED, $summary->status());
    }

    public function testMissingAdapterIsIsolatedAsATargetFailure(): void
    {
        $target = $this->target('missing', 'not-installed', true, 10, ExternalConnectorTarget::CAPABILITY_MEDIA);
        $executor = new ExternalConnectorExecutor(
            new ExternalConnectorRegistry($this->source([$target])),
            new ExternalConnectorAdapterRegistry([]),
        );

        $summary = $executor->execute(ExternalConnectorTarget::CAPABILITY_MEDIA, static function (): void {
            self::fail('Operation must not run without an adapter.');
        });

        self::assertSame(ExternalConnectorExecutionSummary::STATUS_FAILED, $summary->status());
        self::assertSame(0, $summary->successfulCount());
    }

    public function testNoTargetsIsReportedAsNotConfigured(): void
    {
        $executor = new ExternalConnectorExecutor(
            new ExternalConnectorRegistry($this->source([])),
            new ExternalConnectorAdapterRegistry([]),
        );

        $summary = $executor->execute(ExternalConnectorTarget::CAPABILITY_MAIL, static function (): void {});

        self::assertSame(ExternalConnectorExecutionSummary::STATUS_NOT_CONFIGURED, $summary->status());
    }

    /** @param list<ExternalConnectorTarget> $targets */
    private function executor(array $targets): ExternalConnectorExecutor
    {
        $providers = array_values(array_unique(array_map(
            static fn (ExternalConnectorTarget $target): string => $target->getProviderKey(),
            $targets,
        )));
        $adapters = array_map(
            fn (string $provider): ExternalConnectorAdapter => $this->adapter($provider),
            $providers,
        );

        return new ExternalConnectorExecutor(
            new ExternalConnectorRegistry($this->source($targets)),
            new ExternalConnectorAdapterRegistry($adapters),
        );
    }

    private function adapter(string $provider): ExternalConnectorAdapter
    {
        return new class($provider) implements ExternalConnectorAdapter {
            public function __construct(private readonly string $provider) {}
            public function providerKey(): string { return $this->provider; }
            public function supports(string $capability): bool { return true; }
        };
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

    private function target(
        string $key,
        string $provider,
        bool $required,
        int $priority,
        string $capability = ExternalConnectorTarget::CAPABILITY_MAIL,
    ): ExternalConnectorTarget
    {
        return (new ExternalConnectorTarget())
            ->setCapability($capability)
            ->setTargetKey($key)
            ->setProviderKey($provider)
            ->setDisplayName($key)
            ->setConfigurationReference('connector.'.$key)
            ->setRequired($required)
            ->setPriority($priority)
            ->setEnabled(true);
    }
}

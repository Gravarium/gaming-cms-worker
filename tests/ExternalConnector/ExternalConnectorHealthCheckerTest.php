<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\ExternalConnectorAdapterRegistry;
use App\ExternalConnector\ExternalConnectorExecutionSummary;
use App\ExternalConnector\ExternalConnectorExecutor;
use App\ExternalConnector\ExternalConnectorHealthCheckAdapter;
use App\ExternalConnector\ExternalConnectorHealthChecker;
use App\ExternalConnector\ExternalConnectorRegistry;
use App\ExternalConnector\ExternalConnectorTargetDefinition;
use App\ExternalConnector\ExternalConnectorTargetSource;
use PHPUnit\Framework\TestCase;

final class ExternalConnectorHealthCheckerTest extends TestCase
{
    public function testChecksEveryTargetAndSanitizesProviderFailure(): void
    {
        $targets = [
            $this->target('primary', 'healthy', true),
            $this->target('optional', 'broken', false),
        ];
        $source = new class($targets) implements ExternalConnectorTargetSource {
            public function __construct(private readonly array $targets) {}
            public function enabledFor(string $capability): array { return $this->targets; }
        };
        $adapters = [
            $this->adapter('healthy', false),
            $this->adapter('broken', true),
        ];
        $checker = new ExternalConnectorHealthChecker(new ExternalConnectorExecutor(
            new ExternalConnectorRegistry($source),
            new ExternalConnectorAdapterRegistry($adapters),
        ));

        $summary = $checker->check(ExternalConnectorTarget::CAPABILITY_MEDIA);

        self::assertSame(ExternalConnectorExecutionSummary::STATUS_DEGRADED, $summary->status());
        self::assertTrue($summary->results[0]->successful);
        self::assertFalse($summary->results[1]->successful);
        self::assertObjectNotHasProperty('message', $summary->results[1]);
    }

    private function adapter(string $provider, bool $fail): ExternalConnectorHealthCheckAdapter
    {
        return new class($provider, $fail) implements ExternalConnectorHealthCheckAdapter {
            public function __construct(private readonly string $provider, private readonly bool $fail) {}
            public function providerKey(): string { return $this->provider; }
            public function supports(string $capability): bool { return true; }
            public function check(ExternalConnectorTargetDefinition $target): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('secret provider response');
                }
            }
        };
    }

    private function target(string $key, string $provider, bool $required): ExternalConnectorTarget
    {
        return (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_MEDIA)
            ->setTargetKey($key)
            ->setProviderKey($provider)
            ->setDisplayName($key)
            ->setConfigurationReference('media.'.$key)
            ->setRequired($required)
            ->setEnabled(true);
    }
}

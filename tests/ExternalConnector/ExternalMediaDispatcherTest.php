<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\ExternalConnectorAdapterRegistry;
use App\ExternalConnector\ExternalConnectorExecutionSummary;
use App\ExternalConnector\ExternalConnectorExecutor;
use App\ExternalConnector\ExternalConnectorRegistry;
use App\ExternalConnector\ExternalConnectorTargetDefinition;
use App\ExternalConnector\ExternalConnectorTargetSource;
use App\ExternalConnector\ExternalMediaConnectorAdapter;
use App\ExternalConnector\ExternalMediaDispatcher;
use App\ExternalConnector\ExternalMediaObject;
use App\ExternalConnector\ExternalMediaUpload;
use PHPUnit\Framework\TestCase;

final class ExternalMediaDispatcherTest extends TestCase
{
    public function testStoresOnEveryTargetAndReturnsObjectsByTarget(): void
    {
        $calls = new \ArrayObject();
        $dispatcher = $this->dispatcher(
            [
                $this->target('primary', 'store-a', true),
                $this->target('replica', 'store-b', false),
            ],
            [
                $this->adapter('store-a', $calls),
                $this->adapter('store-b', $calls),
            ],
        );

        $result = $dispatcher->store(new ExternalMediaUpload('video/file.mp4', '/tmp/file.mp4', 'video/mp4'));

        self::assertSame(ExternalConnectorExecutionSummary::STATUS_HEALTHY, $result->summary->status());
        self::assertSame(['primary', 'replica'], array_keys($result->objectsByTarget));
        self::assertSame(['store:primary', 'store:replica'], iterator_to_array($calls));
    }

    public function testOptionalFailureKeepsSuccessfulReplica(): void
    {
        $calls = new \ArrayObject();
        $dispatcher = $this->dispatcher(
            [
                $this->target('primary', 'store-a', true),
                $this->target('optional', 'broken', false),
            ],
            [
                $this->adapter('store-a', $calls),
                $this->adapter('broken', $calls, true),
            ],
        );

        $result = $dispatcher->store(new ExternalMediaUpload('image/file.webp', '/tmp/file.webp'));

        self::assertSame(ExternalConnectorExecutionSummary::STATUS_DEGRADED, $result->summary->status());
        self::assertSame(['primary'], array_keys($result->objectsByTarget));
    }

    public function testRequiredFailureRollsBackSuccessfulUploads(): void
    {
        $calls = new \ArrayObject();
        $dispatcher = $this->dispatcher(
            [
                $this->target('first', 'store-a', true),
                $this->target('required-broken', 'broken', true),
            ],
            [
                $this->adapter('store-a', $calls),
                $this->adapter('broken', $calls, true),
            ],
        );

        $result = $dispatcher->store(new ExternalMediaUpload('image/file.webp', '/tmp/file.webp'));

        self::assertSame(ExternalConnectorExecutionSummary::STATUS_FAILED, $result->summary->status());
        self::assertSame([], $result->objectsByTarget);
        self::assertSame(['store:first', 'store:required-broken', 'delete:first'], iterator_to_array($calls));
    }

    /** @param \ArrayObject<int, string> $calls */
    private function adapter(string $provider, \ArrayObject $calls, bool $fail = false): ExternalMediaConnectorAdapter
    {
        return new class($provider, $calls, $fail) implements ExternalMediaConnectorAdapter {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(
                private readonly string $provider,
                private readonly \ArrayObject $calls,
                private readonly bool $fail,
            ) {
            }

            public function providerKey(): string { return $this->provider; }
            public function supports(string $capability): bool { return $capability === ExternalConnectorTarget::CAPABILITY_MEDIA; }

            public function store(ExternalConnectorTargetDefinition $target, ExternalMediaUpload $upload): ExternalMediaObject
            {
                $this->calls[] = 'store:'.$target->targetKey;
                if ($this->fail) {
                    throw new \RuntimeException('Provider failure with potentially sensitive details.');
                }

                return new ExternalMediaObject($upload->objectKey, 'https://media.invalid/'.$target->targetKey.'/'.$upload->objectKey, 123);
            }

            public function delete(ExternalConnectorTargetDefinition $target, string $objectKey): void
            {
                $this->calls[] = 'delete:'.$target->targetKey;
            }
        };
    }

    /**
     * @param list<ExternalConnectorTarget> $targets
     * @param list<ExternalMediaConnectorAdapter> $adapters
     */
    private function dispatcher(array $targets, array $adapters): ExternalMediaDispatcher
    {
        $source = new class($targets) implements ExternalConnectorTargetSource {
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

        $registry = new ExternalConnectorRegistry($source);
        $adapterRegistry = new ExternalConnectorAdapterRegistry($adapters);

        return new ExternalMediaDispatcher(
            new ExternalConnectorExecutor($registry, $adapterRegistry),
            $registry,
            $adapterRegistry,
        );
    }

    private function target(string $key, string $provider, bool $required): ExternalConnectorTarget
    {
        return (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_MEDIA)
            ->setTargetKey($key)
            ->setProviderKey($provider)
            ->setDisplayName($key)
            ->setConfigurationReference('connector.'.$key)
            ->setRequired($required)
            ->setEnabled(true);
    }
}

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
use App\ExternalConnector\ExternalMailConnectorAdapter;
use App\ExternalConnector\ExternalMailDispatcher;
use App\ExternalConnector\ExternalMailMessage;
use App\ExternalConnector\ExternalNotificationConnectorAdapter;
use App\ExternalConnector\ExternalNotificationDispatcher;
use App\ExternalConnector\ExternalNotificationMessage;
use PHPUnit\Framework\TestCase;

final class ExternalCapabilityContractTest extends TestCase
{
    public function testMailDispatcherUsesEveryConfiguredMailAdapter(): void
    {
        $sent = new \ArrayObject();
        $targets = [
            $this->target('mail-primary', 'smtp-a', ExternalConnectorTarget::CAPABILITY_MAIL, true),
            $this->target('mail-secondary', 'api-b', ExternalConnectorTarget::CAPABILITY_MAIL, false),
        ];
        $adapters = [
            $this->mailAdapter('smtp-a', $sent),
            $this->mailAdapter('api-b', $sent),
        ];

        $summary = (new ExternalMailDispatcher($this->executor($targets, $adapters)))
            ->send(new ExternalMailMessage(['member@example.invalid'], 'Subject', 'Body'));

        self::assertSame(['mail-primary', 'mail-secondary'], iterator_to_array($sent));
        self::assertSame(ExternalConnectorExecutionSummary::STATUS_HEALTHY, $summary->status());
    }

    public function testNotificationDispatcherRejectsAnAdapterWithoutNotificationContract(): void
    {
        $targets = [$this->target('notify', 'mail-only', ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS, true)];
        $sent = new \ArrayObject();

        $summary = (new ExternalNotificationDispatcher($this->executor(
            $targets,
            [$this->mailAdapter('mail-only', $sent)],
        )))->send(new ExternalNotificationMessage('announcement', 'Title', 'Message'));

        self::assertSame(ExternalConnectorExecutionSummary::STATUS_FAILED, $summary->status());
        self::assertSame([], iterator_to_array($sent));
    }

    /** @param \ArrayObject<int, string> $sent */
    private function mailAdapter(string $provider, \ArrayObject $sent): ExternalMailConnectorAdapter
    {
        return new class($provider, $sent) implements ExternalMailConnectorAdapter {
            /** @param \ArrayObject<int, string> $sent */
            public function __construct(private readonly string $provider, private readonly \ArrayObject $sent) {}
            public function providerKey(): string { return $this->provider; }
            public function supports(string $capability): bool { return true; }
            public function send(ExternalConnectorTargetDefinition $target, ExternalMailMessage $message): void
            {
                $this->sent[] = $target->targetKey;
            }
        };
    }

    /**
     * @param list<ExternalConnectorTarget> $targets
     * @param list<\App\ExternalConnector\ExternalConnectorAdapter> $adapters
     */
    private function executor(array $targets, array $adapters): ExternalConnectorExecutor
    {
        return new ExternalConnectorExecutor(
            new ExternalConnectorRegistry($this->source($targets)),
            new ExternalConnectorAdapterRegistry($adapters),
        );
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

    private function target(string $key, string $provider, string $capability, bool $required): ExternalConnectorTarget
    {
        return (new ExternalConnectorTarget())
            ->setCapability($capability)
            ->setTargetKey($key)
            ->setProviderKey($provider)
            ->setDisplayName($key)
            ->setConfigurationReference('connector.'.$key)
            ->setRequired($required)
            ->setEnabled(true);
    }
}

<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class ExternalConnectorExecutor
{
    private const MAX_EXECUTION_TARGETS = 64;

    public function __construct(
        private ExternalConnectorRegistry $targets,
        private ExternalConnectorAdapterRegistry $adapters,
    ) {
    }

    /**
     * @param callable(ExternalConnectorAdapter, ExternalConnectorTargetDefinition): void $operation
     */
    public function execute(string $capability, callable $operation): ExternalConnectorExecutionSummary
    {
        $targets = $this->targets->forCapability($capability);
        if (count($targets) > self::MAX_EXECUTION_TARGETS) {
            throw new \DomainException('External connector execution fanout exceeds its safe bound.');
        }

        $results = [];
        foreach ($targets as $target) {
            try {
                $operation($this->adapters->forTarget($target), $target);
                $results[] = ExternalConnectorExecutionResult::success($target);
            } catch (\Throwable) {
                // Provider errors are deliberately isolated and their messages may contain secrets.
                $results[] = ExternalConnectorExecutionResult::failure($target);
            }
        }

        return new ExternalConnectorExecutionSummary($capability, $results);
    }
}

<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class ExternalConnectorHealthChecker
{
    public function __construct(private ExternalConnectorExecutor $executor)
    {
    }

    public function check(string $capability): ExternalConnectorExecutionSummary
    {
        return $this->executor->execute(
            $capability,
            static function (ExternalConnectorAdapter $adapter, ExternalConnectorTargetDefinition $target): void {
                if (!$adapter instanceof ExternalConnectorHealthCheckAdapter) {
                    throw new \LogicException('The selected adapter does not implement health checks.');
                }

                $adapter->check($target);
            },
        );
    }
}

<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

final readonly class ExternalNotificationDispatcher
{
    public function __construct(private ExternalConnectorExecutor $executor) {}

    public function send(ExternalNotificationMessage $message): ExternalConnectorExecutionSummary
    {
        return $this->executor->execute(
            ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS,
            static function (ExternalConnectorAdapter $adapter, ExternalConnectorTargetDefinition $target) use ($message): void {
                if (!$adapter instanceof ExternalNotificationConnectorAdapter) {
                    throw new \LogicException('The selected adapter does not implement the notification contract.');
                }

                $adapter->send($target, $message);
            },
        );
    }
}

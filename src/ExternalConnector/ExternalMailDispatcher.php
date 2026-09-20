<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

final readonly class ExternalMailDispatcher
{
    public function __construct(private ExternalConnectorExecutor $executor) {}

    public function send(ExternalMailMessage $message): ExternalConnectorExecutionSummary
    {
        return $this->executor->execute(
            ExternalConnectorTarget::CAPABILITY_MAIL,
            static function (ExternalConnectorAdapter $adapter, ExternalConnectorTargetDefinition $target) use ($message): void {
                if (!$adapter instanceof ExternalMailConnectorAdapter) {
                    throw new \LogicException('The selected adapter does not implement the mail contract.');
                }

                $adapter->send($target, $message);
            },
        );
    }
}

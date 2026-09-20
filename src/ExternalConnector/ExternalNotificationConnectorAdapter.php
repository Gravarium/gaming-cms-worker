<?php

declare(strict_types=1);

namespace App\ExternalConnector;

interface ExternalNotificationConnectorAdapter extends ExternalConnectorAdapter
{
    public function send(ExternalConnectorTargetDefinition $target, ExternalNotificationMessage $message): void;
}

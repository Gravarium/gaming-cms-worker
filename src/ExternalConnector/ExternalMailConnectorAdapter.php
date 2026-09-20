<?php

declare(strict_types=1);

namespace App\ExternalConnector;

interface ExternalMailConnectorAdapter extends ExternalConnectorAdapter
{
    public function send(ExternalConnectorTargetDefinition $target, ExternalMailMessage $message): void;
}

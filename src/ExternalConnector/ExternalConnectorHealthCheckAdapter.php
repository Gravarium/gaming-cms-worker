<?php

declare(strict_types=1);

namespace App\ExternalConnector;

interface ExternalConnectorHealthCheckAdapter extends ExternalConnectorAdapter
{
    public function check(ExternalConnectorTargetDefinition $target): void;
}

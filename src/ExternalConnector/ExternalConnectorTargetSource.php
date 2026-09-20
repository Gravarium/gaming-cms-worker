<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

interface ExternalConnectorTargetSource
{
    /** @return list<ExternalConnectorTarget> */
    public function enabledFor(string $capability): array;
}

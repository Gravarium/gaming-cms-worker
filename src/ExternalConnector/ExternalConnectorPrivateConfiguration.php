<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

interface ExternalConnectorPrivateConfiguration
{
    public const READY = 'ready';
    public const MISSING = 'missing';

    /** @param iterable<ExternalConnectorTarget> $targets
     *  @return array<string, 'ready'|'missing'>
     */
    public function forTargets(iterable $targets): array;

    /** @return array<string, mixed> */
    public function forTarget(ExternalConnectorTargetDefinition $target): array;
}

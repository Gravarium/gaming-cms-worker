<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class ExternalMediaStoreResult
{
    /**
     * On successful/degraded stores this contains the objects that remain stored.
     * On a failed required-target store it contains only objects whose rollback also failed.
     *
     * @param array<string, ExternalMediaObject> $objectsByTarget
     */
    public function __construct(
        public ExternalConnectorExecutionSummary $summary,
        public array $objectsByTarget,
    ) {
    }
}

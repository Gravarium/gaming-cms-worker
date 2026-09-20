<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class ExternalMediaStoreResult
{
    /** @param array<string, ExternalMediaObject> $objectsByTarget */
    public function __construct(
        public ExternalConnectorExecutionSummary $summary,
        public array $objectsByTarget,
    ) {
    }
}

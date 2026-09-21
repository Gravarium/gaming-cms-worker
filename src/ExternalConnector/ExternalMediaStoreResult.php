<?php

declare(strict_types=1);

namespace App\ExternalConnector;

final readonly class ExternalMediaStoreResult
{
    /**
     * On successful/degraded stores objectsByTarget contains objects that remain stored.
     * On a failed required-target store it contains only successful objects whose rollback also failed.
     * cleanupObjectKeysByTarget additionally records writes whose store result was uncertain and whose
     * compensating delete also failed. The caller must preserve those keys in its repair journal when
     * the complete media operation is aborted.
     *
     * @param array<string, ExternalMediaObject> $objectsByTarget
     * @param array<string, string> $cleanupObjectKeysByTarget
     */
    public function __construct(
        public ExternalConnectorExecutionSummary $summary,
        public array $objectsByTarget,
        public array $cleanupObjectKeysByTarget = [],
    ) {
    }
}

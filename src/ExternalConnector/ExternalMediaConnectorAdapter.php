<?php

declare(strict_types=1);

namespace App\ExternalConnector;

interface ExternalMediaConnectorAdapter extends ExternalConnectorAdapter
{
    public function store(ExternalConnectorTargetDefinition $target, ExternalMediaUpload $upload): ExternalMediaObject;

    /**
     * Delete must be idempotent: retrying an already deleted object key must be treated as success.
     */
    public function delete(ExternalConnectorTargetDefinition $target, string $objectKey): void;
}

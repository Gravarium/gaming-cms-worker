<?php

declare(strict_types=1);

namespace App\ExternalConnector;

interface ExternalMediaConnectorAdapter extends ExternalConnectorAdapter
{
    public function store(ExternalConnectorTargetDefinition $target, ExternalMediaUpload $upload): ExternalMediaObject;

    public function delete(ExternalConnectorTargetDefinition $target, string $objectKey): void;
}

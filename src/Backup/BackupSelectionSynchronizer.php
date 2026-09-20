<?php

declare(strict_types=1);

namespace App\Backup;

use App\ExternalConnector\BackupTargetSelectionExporter;

final readonly class BackupSelectionSynchronizer
{
    private string $outputFile;

    public function __construct(
        private BackupTargetSelectionExporter $exporter,
        string $projectDir,
    ) {
        $this->outputFile = $projectDir.'/var/backup-selection.conf';
    }

    public function synchronize(): void
    {
        $parent = dirname($this->outputFile);
        if (!is_dir($parent) || is_link($parent)) {
            throw new \RuntimeException('Das Verzeichnis für die Backup-Auswahl ist nicht sicher verfügbar.');
        }

        $temporary = tempnam($parent, '.backup-selection.');
        if ($temporary === false) {
            throw new \RuntimeException('Die Backup-Auswahl konnte nicht vorbereitet werden.');
        }

        try {
            if (
                file_put_contents($temporary, $this->exporter->export(true), LOCK_EX) === false
                || !chmod($temporary, 0600)
                || !rename($temporary, $this->outputFile)
            ) {
                throw new \RuntimeException('Die Backup-Auswahl konnte nicht atomar gespeichert werden.');
            }
        } catch (\Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }
    }

    public function outputFile(): string
    {
        return $this->outputFile;
    }
}

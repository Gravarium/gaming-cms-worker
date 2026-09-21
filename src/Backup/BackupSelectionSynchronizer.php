<?php

declare(strict_types=1);

namespace App\Backup;

use App\ExternalConnector\BackupTargetSelectionExporter;

final readonly class BackupSelectionSynchronizer
{
    private string $outputFile;
    private string $pendingFile;

    public function __construct(
        private BackupTargetSelectionExporter $exporter,
        string $projectDir,
    ) {
        $this->outputFile = $projectDir.'/var/backup-selection.conf';
        $this->pendingFile = $projectDir.'/var/backup-selection.pending';
    }

    public function markPending(): void
    {
        $parent = $this->safeParent();
        $temporary = tempnam($parent, '.backup-selection-pending.');
        if ($temporary === false) {
            throw new \RuntimeException('Die ausstehende Backup-Auswahl konnte nicht vorgemerkt werden.');
        }

        try {
            if (file_put_contents($temporary, "pending\n", LOCK_EX) === false
                || !chmod($temporary, 0600)
                || !rename($temporary, $this->pendingFile)
            ) {
                throw new \RuntimeException('Die ausstehende Backup-Auswahl konnte nicht sicher vorgemerkt werden.');
            }
        } catch (\Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }
    }

    public function synchronize(): void
    {
        $parent = $this->safeParent();
        $temporary = tempnam($parent, '.backup-selection.');
        if ($temporary === false) {
            throw new \RuntimeException('Die Backup-Auswahl konnte nicht vorbereitet werden.');
        }

        try {
            if (file_put_contents($temporary, $this->exporter->export(true), LOCK_EX) === false
                || !chmod($temporary, 0600)
                || !rename($temporary, $this->outputFile)
            ) {
                throw new \RuntimeException('Die Backup-Auswahl konnte nicht atomar gespeichert werden.');
            }

            if (($this->hasPending()) && !unlink($this->pendingFile)) {
                throw new \RuntimeException('Die Backup-Auswahl wurde geschrieben, aber der Reparaturmarker konnte nicht entfernt werden.');
            }
        } catch (\Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }
    }

    public function hasPending(): bool
    {
        return is_file($this->pendingFile) || is_link($this->pendingFile);
    }

    public function outputFile(): string
    {
        return $this->outputFile;
    }

    public function pendingFile(): string
    {
        return $this->pendingFile;
    }

    private function safeParent(): string
    {
        $parent = dirname($this->outputFile);
        if (!is_dir($parent) || is_link($parent)) {
            throw new \RuntimeException('Das Verzeichnis für die Backup-Auswahl ist nicht sicher verfügbar.');
        }

        return $parent;
    }
}

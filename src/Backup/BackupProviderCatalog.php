<?php

declare(strict_types=1);

namespace App\Backup;

final class BackupProviderCatalog
{
    /**
     * @return list<array{key: string, name: string, transport: string, supported: bool}>
     */
    public function all(): array
    {
        return [
            $this->provider('pcloud', 'pCloud', 'rclone', true),
            $this->provider('google-drive', 'Google Drive', 'rclone', true),
            $this->provider('dropbox', 'Dropbox', 'rclone', true),
            $this->provider('onedrive', 'Microsoft OneDrive', 'rclone', true),
            $this->provider('mega', 'MEGA', 'rclone', true),
            $this->provider('icedrive', 'Icedrive', 'WebDAV/Adapter', false),
            $this->provider('proton-drive', 'Proton Drive', 'Adapter', false),
            $this->provider('box', 'Box', 'rclone', true),
            $this->provider('icloud', 'Apple iCloud', 'Adapter', false),
            $this->provider('internxt', 'Internxt', 'WebDAV/Adapter', false),
            $this->provider('sync-com', 'Sync.com', 'Adapter', false),
            $this->provider('filen', 'Filen', 'Adapter', false),
            $this->provider('kdrive', 'Infomaniak kDrive', 'WebDAV/rclone', true),
            $this->provider('mediafire', 'MediaFire', 'Adapter', false),
            $this->provider('degoo', 'Degoo', 'Adapter', false),
            $this->provider('yandex-disk', 'Yandex Disk', 'WebDAV/rclone', true),
            $this->provider('gmx-cloud', 'GMX Cloud', 'WebDAV', true),
            $this->provider('webde-cloud', 'WEB.DE Cloud', 'WebDAV', true),
            $this->provider('s3-compatible', 'S3-kompatibler Speicher', 'restic/rclone', true),
            $this->provider('sftp-server', 'Eigener Server per SFTP', 'restic', true),
        ];
    }

    /** @return array<string, array{key: string, name: string, transport: string, supported: bool}> */
    public function indexed(): array
    {
        $indexed = [];
        foreach ($this->all() as $provider) {
            $indexed[$provider['key']] = $provider;
        }

        return $indexed;
    }

    /** @return array{key: string, name: string, transport: string, supported: bool} */
    private function provider(string $key, string $name, string $transport, bool $supported): array
    {
        return ['key' => $key, 'name' => $name, 'transport' => $transport, 'supported' => $supported];
    }
}

<?php

declare(strict_types=1);

namespace App\Downloads;

use App\Entity\Download\DownloadVersion;

final readonly class DownloadScanService
{
    public function __construct(private DownloadPrivateStorage $storage)
    {
    }

    public function rescan(DownloadVersion $version): string
    {
        $version->beginScan();

        try {
            $version->markScan($this->storage->rescan($version->getStorageReference()));
        } catch (DownloadStorageUnavailable) {
            $version->markScan(
                DownloadVersion::SCAN_UNAVAILABLE,
                DownloadVersion::SCAN_ERROR_STORAGE_UNAVAILABLE,
            );
        } catch (\DomainException) {
            $version->markScan(
                DownloadVersion::SCAN_REJECTED,
                DownloadVersion::SCAN_ERROR_MALWARE_REJECTED,
            );
        } catch (\LogicException|\RuntimeException) {
            $version->markScan(
                DownloadVersion::SCAN_UNAVAILABLE,
                DownloadVersion::SCAN_ERROR_SCANNER_UNAVAILABLE,
            );
        } catch (\Throwable) {
            $version->markScan(
                DownloadVersion::SCAN_UNAVAILABLE,
                DownloadVersion::SCAN_ERROR_SCAN_FAILED,
            );
        }

        return $version->getScanStatus();
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ExternalConnectorTarget;
use App\Entity\MediaAsset;
use App\Entity\MediaAssetReplica;
use App\Entity\MediaReplicationTask;
use App\Entity\ModuleStorageSetting;
use App\ExternalConnector\ExternalConnectorExecutionSummary;
use App\ExternalConnector\ExternalConnectorRegistry;
use App\ExternalConnector\ExternalMediaDispatcher;
use App\ExternalConnector\ExternalMediaObject;
use App\ExternalConnector\ExternalMediaUpload;
use App\Repository\ModuleStorageSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

final class MediaStorageManager
{
    /** @var array<int, array{kind: 'connector'|'legacy_s3'|'local', value: string}> */
    private array $ownedStorage = [];

    /** @var array<int, list<string>> */
    private array $stagedFiles = [];

    public function __construct(
        private readonly ModuleStorageSettingRepository $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
        private readonly S3ObjectStorage $objectStorage,
        private readonly ExternalConnectorRegistry $connectorTargets,
        private readonly ExternalMediaDispatcher $mediaDispatcher,
        private readonly MediaUploadPolicy $uploadPolicy,
        private readonly MediaUrlPolicy $urlPolicy,
        private readonly MediaStorageCleanupJournal $cleanupJournal,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function modeFor(string $moduleKey): string
    {
        return $this->settingFor($moduleKey)?->getStorageMode() ?? ModuleStorageSetting::MODE_INTERNAL;
    }

    public function externalUploadReady(): bool
    {
        return $this->connectorTargets->hasTargets(ExternalConnectorTarget::CAPABILITY_MEDIA)
            || $this->objectStorage->isConfigured();
    }

    public function storeUpload(UploadedFile $file, string $moduleKey, ?string $altText = null): MediaAsset
    {
        $this->uploadPolicy->assertSafe($file, $moduleKey);

        $safeName = mb_strtolower($this->slugger->slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))->toString()) ?: 'datei';
        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
        $filename = $safeName.'-'.bin2hex(random_bytes(8)).'.'.$extension;
        $objectKey = $moduleKey.'/'.$filename;
        $size = $file->getSize();
        $mimeType = $file->getMimeType();
        $originalName = $file->getClientOriginalName();
        $checksum = hash_file('sha256', $file->getPathname()) ?: null;
        $mode = $this->modeFor($moduleKey);
        $connectorManaged = $mode === ModuleStorageSetting::MODE_EXTERNAL
            && $this->connectorTargets->hasTargets(ExternalConnectorTarget::CAPABILITY_MEDIA);

        $storeResult = null;
        $stagedPaths = [];
        $legacyObjectKey = null;
        $localLocation = null;

        if ($connectorManaged) {
            $storeResult = $this->mediaDispatcher->store(new ExternalMediaUpload(
                $objectKey,
                $file->getPathname(),
                $mimeType,
            ));

            if ($storeResult->summary->status() === ExternalConnectorExecutionSummary::STATUS_FAILED) {
                $this->journalConnectorObjects($storeResult->objectsByTarget);
                $this->journalConnectorKeys($storeResult->cleanupObjectKeysByTarget);
                throw new \RuntimeException('Der Upload konnte auf den erforderlichen Medienzielen nicht gespeichert werden.');
            }

            if ($storeResult->objectsByTarget === []) {
                throw new \RuntimeException('Kein Medienziel hat den Upload gespeichert.');
            }

            try {
                foreach ($storeResult->objectsByTarget as $object) {
                    $this->assertStorableExternalObject($object);
                }
            } catch (\DomainException $exception) {
                $this->cleanupConnectorObjects($storeResult->objectsByTarget);
                $this->journalConnectorKeys($storeResult->cleanupObjectKeysByTarget);
                throw $exception;
            }

            $firstObject = array_values($storeResult->objectsByTarget)[0];
            $mode = ModuleStorageSetting::MODE_EXTERNAL;
            $location = $firstObject->location;

            try {
                $stagedPaths = $this->stageOptionalReplicationFailures(
                    $storeResult->summary,
                    $objectKey,
                    $file->getPathname(),
                );
            } catch (\RuntimeException $exception) {
                $this->cleanupConnectorObjects($storeResult->objectsByTarget);
                $this->journalConnectorKeys($storeResult->cleanupObjectKeysByTarget);
                throw $exception;
            }
        } elseif ($mode === ModuleStorageSetting::MODE_EXTERNAL) {
            $setting = $this->settingFor($moduleKey);
            $location = $this->objectStorage->upload(
                $objectKey,
                $file->getPathname(),
                $mimeType,
                $setting?->getExternalBaseUrl(),
            );
            try {
                if (mb_strlen($location) > 500) {
                    throw new \DomainException('Die externe Speicheradresse ist zu lang.');
                }
                $this->urlPolicy->assertSafeRemote($location);
            } catch (\DomainException $exception) {
                try {
                    $this->objectStorage->delete($objectKey);
                } catch (\Throwable) {
                    $this->cleanupJournal->recordLegacyS3($objectKey);
                }
                throw $exception;
            }
            $legacyObjectKey = $objectKey;
        } else {
            $directory = $this->projectDir.'/public/uploads/media/'.$moduleKey;
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException('Der Upload-Ordner konnte nicht erstellt werden.');
            }
            $file->move($directory, $filename);
            $location = '/uploads/media/'.$objectKey;
            $localLocation = $location;
        }

        $asset = (new MediaAsset())
            ->setModuleKey($moduleKey)
            ->setStorageMode($mode)
            ->setLocation($location)
            ->setOriginalName($originalName)
            ->setTitle(mb_substr(pathinfo($originalName, PATHINFO_FILENAME) ?: $originalName, 0, 180))
            ->setChecksumSha256($checksum)
            ->setMimeType($mimeType)
            ->setFileSize($size === false ? null : $size)
            ->setAltText($altText);

        if ($storeResult !== null) {
            $providerByTarget = [];
            foreach ($storeResult->summary->results as $result) {
                $providerByTarget[$result->targetKey] = $result->providerKey;
            }

            foreach ($storeResult->objectsByTarget as $targetKey => $object) {
                $providerKey = $providerByTarget[$targetKey] ?? null;
                if ($providerKey === null) {
                    $this->cleanupConnectorObjects($storeResult->objectsByTarget);
                    $this->cleanupStagedPaths($stagedPaths);
                    throw new \RuntimeException('Das Medienziel lieferte einen inkonsistenten Speicherstatus.');
                }

                $asset->addReplica(
                    (new MediaAssetReplica())
                        ->setTargetKey($targetKey)
                        ->setProviderKey($providerKey)
                        ->setObjectKey($object->objectKey)
                        ->setLocation($object->location),
                );
            }

            foreach ($storeResult->summary->results as $result) {
                if ($result->successful || $result->required) {
                    continue;
                }

                $stagedPath = array_shift($stagedPaths);
                if ($stagedPath === null) {
                    $this->cleanupConnectorObjects($storeResult->objectsByTarget);
                    throw new \RuntimeException('Die optionale Medienreplikation konnte nicht sicher vorgemerkt werden.');
                }

                $task = (new MediaReplicationTask())
                    ->setAsset($asset)
                    ->setTargetKey($result->targetKey)
                    ->setProviderKey($result->providerKey)
                    ->setObjectKey($objectKey)
                    ->setStagedFilename(basename($stagedPath));
                $this->entityManager->persist($task);
                $this->stagedFiles[spl_object_id($asset)][] = $stagedPath;
            }

            $this->ownedStorage[spl_object_id($asset)] = ['kind' => 'connector', 'value' => ''];
        } elseif ($legacyObjectKey !== null) {
            $this->ownedStorage[spl_object_id($asset)] = ['kind' => 'legacy_s3', 'value' => $legacyObjectKey];
        } elseif ($localLocation !== null) {
            $this->ownedStorage[spl_object_id($asset)] = ['kind' => 'local', 'value' => $localLocation];
        }

        $this->entityManager->persist($asset);

        return $asset;
    }

    public function storeExternal(string $url, string $moduleKey, ?string $altText = null): MediaAsset
    {
        $this->uploadPolicy->maxBytesFor($moduleKey);
        if ($this->modeFor($moduleKey) !== ModuleStorageSetting::MODE_EXTERNAL) {
            throw new \DomainException('Dieses Modul ist auf interne Speicherung eingestellt. Bitte eine Datei hochladen.');
        }
        $this->urlPolicy->assertSafeRemote($url);
        if (mb_strlen(trim($url)) > 500) {
            throw new \DomainException('Die externe Medienadresse ist zu lang.');
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $originalName = rawurldecode(basename($path)) ?: 'Externe Datei';
        $originalName = mb_substr($originalName, 0, 255);
        $asset = (new MediaAsset())
            ->setModuleKey($moduleKey)
            ->setStorageMode(ModuleStorageSetting::MODE_EXTERNAL)
            ->setLocation(trim($url))
            ->setOriginalName($originalName)
            ->setTitle(mb_substr(pathinfo($originalName, PATHINFO_FILENAME) ?: $originalName, 0, 180))
            ->setAltText($altText);
        $this->entityManager->persist($asset);

        return $asset;
    }

    public function flushWithRollback(MediaAsset ...$newAssets): void
    {
        try {
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->discardUncommitted(...$newAssets);
            throw new \RuntimeException(
                'Die Medienänderung konnte nicht sicher in der Datenbank bestätigt werden. Angelegte Speicherobjekte wurden zurückgerollt oder zur Reparatur vorgemerkt.',
                0,
                $exception,
            );
        }

        foreach ($newAssets as $asset) {
            $id = spl_object_id($asset);
            unset($this->ownedStorage[$id], $this->stagedFiles[$id]);
        }
    }

    public function discardUncommitted(MediaAsset ...$assets): void
    {
        foreach ($assets as $asset) {
            $id = spl_object_id($asset);
            $descriptor = $this->ownedStorage[$id] ?? null;

            if ($descriptor !== null) {
                if ($descriptor['kind'] === 'connector') {
                    foreach ($asset->getReplicas() as $replica) {
                        try {
                            $this->mediaDispatcher->deleteForTarget($replica->getTargetKey(), $replica->getObjectKey());
                        } catch (\Throwable) {
                            $this->cleanupJournal->recordConnector($replica->getTargetKey(), $replica->getObjectKey());
                        }
                    }
                } elseif ($descriptor['kind'] === 'legacy_s3') {
                    try {
                        $this->objectStorage->delete($descriptor['value']);
                    } catch (\Throwable) {
                        $this->cleanupJournal->recordLegacyS3($descriptor['value']);
                    }
                } else {
                    try {
                        $this->deleteLocalLocation($descriptor['value']);
                    } catch (\Throwable) {
                        $this->cleanupJournal->recordLocal($descriptor['value']);
                    }
                }
            }

            $this->cleanupStagedPaths($this->stagedFiles[$id] ?? []);
            unset($this->ownedStorage[$id], $this->stagedFiles[$id]);
        }
    }

    public function delete(MediaAsset $asset): void
    {
        if (!$asset->isDeletionPending()) {
            $asset->markDeletionPending();
            try {
                $this->entityManager->flush();
            } catch (\Throwable $exception) {
                throw new \RuntimeException(
                    'Die Medienlöschung konnte nicht sicher vorgemerkt werden. Es wurde kein Storage-Objekt gelöscht.',
                    0,
                    $exception,
                );
            }
        }

        try {
            $this->deleteStorageFor($asset);
        } catch (\DomainException|\RuntimeException $exception) {
            throw new \RuntimeException(
                'Die Medienlöschung bleibt zur Reparatur vorgemerkt, weil der Storage nicht vollständig bestätigt wurde.',
                0,
                $exception,
            );
        }

        $this->entityManager->remove($asset);
        try {
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'Der Storage wurde gelöscht; der persistierte Löschauftrag bleibt für den nächsten Reparaturlauf erhalten.',
                0,
                $exception,
            );
        }
    }

    private function deleteStorageFor(MediaAsset $asset): void
    {
        if (!$asset->getReplicas()->isEmpty()) {
            foreach ($asset->getReplicas() as $replica) {
                try {
                    $this->mediaDispatcher->deleteForTarget($replica->getTargetKey(), $replica->getObjectKey());
                } catch (\Throwable) {
                    throw new \RuntimeException('Mindestens eine Medienkopie konnte nicht sicher gelöscht werden.');
                }
            }

            return;
        }

        if ($asset->isExternal()) {
            if ($asset->getFileSize() === null) {
                return;
            }
            if (!$this->objectStorage->isConfigured()) {
                throw new \DomainException('Die S3-Zugangsdaten werden benötigt, um diese externe Datei sicher zu löschen.');
            }

            $path = (string) parse_url($asset->getLocation(), PHP_URL_PATH);
            $filename = rawurldecode(basename($path));
            if ($filename === '' || $filename === '.' || $filename === '..') {
                throw new \DomainException('Der gespeicherte externe Medienpfad ist ungültig.');
            }
            $this->objectStorage->delete($asset->getModuleKey().'/'.$filename);

            return;
        }

        $this->deleteLocalLocation($asset->getLocation());
    }

    /**
     * @param array<string, ExternalMediaObject> $objects
     */
    private function cleanupConnectorObjects(array $objects): void
    {
        foreach ($objects as $targetKey => $object) {
            try {
                $this->mediaDispatcher->deleteForTarget($targetKey, $object->objectKey);
            } catch (\Throwable) {
                $this->cleanupJournal->recordConnector($targetKey, $object->objectKey);
            }
        }
    }

    /**
     * @param array<string, ExternalMediaObject> $objects
     */
    private function journalConnectorObjects(array $objects): void
    {
        foreach ($objects as $targetKey => $object) {
            $this->cleanupJournal->recordConnector($targetKey, $object->objectKey);
        }
    }

    /** @param array<string, string> $keys */
    private function journalConnectorKeys(array $keys): void
    {
        foreach ($keys as $targetKey => $objectKey) {
            $this->cleanupJournal->recordConnector($targetKey, $objectKey);
        }
    }

    private function assertStorableExternalObject(ExternalMediaObject $object): void
    {
        if (mb_strlen($object->objectKey) > 500
            || str_starts_with($object->objectKey, '/')
            || str_contains($object->objectKey, '\\')
            || preg_match('/[\x00-\x1F\x7F]/u', $object->objectKey) === 1
        ) {
            throw new \DomainException('Das Medienziel lieferte einen ungültigen Objektschlüssel.');
        }
        foreach (explode('/', $object->objectKey) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \DomainException('Das Medienziel lieferte einen ungültigen Objektschlüssel.');
            }
        }
        if (mb_strlen($object->location) > 500) {
            throw new \DomainException('Das Medienziel lieferte eine zu lange Speicheradresse.');
        }
        $this->urlPolicy->assertSafeRemote($object->location);
    }

    /**
     * @return list<string>
     */
    private function stageOptionalReplicationFailures(
        ExternalConnectorExecutionSummary $summary,
        string $objectKey,
        string $sourcePath,
    ): array {
        $optionalFailures = array_values(array_filter(
            $summary->results,
            static fn ($result): bool => !$result->successful && !$result->required,
        ));
        if ($optionalFailures === []) {
            return [];
        }

        $repairDirectory = $this->projectDir.'/var/media-repair';
        if (!is_dir($repairDirectory) && !mkdir($repairDirectory, 0700, true) && !is_dir($repairDirectory)) {
            throw new \RuntimeException('Fehlgeschlagene optionale Medienkopien konnten nicht zur Reparatur vorgemerkt werden.');
        }

        $paths = [];
        try {
            foreach ($optionalFailures as $_result) {
                $stagedFilename = bin2hex(random_bytes(16)).'.bin';
                $stagedPath = $repairDirectory.'/'.$stagedFilename;
                if (!copy($sourcePath, $stagedPath) || !chmod($stagedPath, 0600)) {
                    throw new \RuntimeException('Fehlgeschlagene optionale Medienkopien konnten nicht zur Reparatur vorgemerkt werden.');
                }
                $paths[] = $stagedPath;
            }
        } catch (\Throwable $exception) {
            $this->cleanupStagedPaths($paths);
            if ($exception instanceof \RuntimeException) {
                throw $exception;
            }

            throw new \RuntimeException('Fehlgeschlagene optionale Medienkopien konnten nicht zur Reparatur vorgemerkt werden.', 0, $exception);
        }

        return $paths;
    }

    /** @param list<string> $paths */
    private function cleanupStagedPaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (str_starts_with($path, $this->projectDir.'/var/media-repair/')
                && (is_file($path) || is_link($path))
            ) {
                @unlink($path);
            }
        }
    }

    private function deleteLocalLocation(string $location): void
    {
        if (!str_starts_with($location, '/uploads/media/')
            || str_contains($location, '..')
            || str_contains($location, '\\')
            || preg_match('/[\x00-\x1F\x7F]/u', $location) === 1
        ) {
            throw new \DomainException('Der gespeicherte lokale Medienpfad ist ungültig.');
        }

        $filePath = $this->projectDir.'/public'.$location;
        if ((is_file($filePath) || is_link($filePath)) && !unlink($filePath)) {
            throw new \RuntimeException('Die Datei konnte nicht vom CMS-Server gelöscht werden.');
        }
    }

    private function settingFor(string $moduleKey): ?ModuleStorageSetting
    {
        return $this->settings->findOneBy(['moduleKey' => $moduleKey]);
    }
}

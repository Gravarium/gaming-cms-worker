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
use App\ExternalConnector\ExternalMediaUpload;
use App\Repository\ModuleStorageSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

final class MediaStorageManager
{
    public function __construct(
        private readonly ModuleStorageSettingRepository $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
        private readonly S3ObjectStorage $objectStorage,
        private readonly ExternalConnectorRegistry $connectorTargets,
        private readonly ExternalMediaDispatcher $mediaDispatcher,
        private readonly MediaUploadPolicy $uploadPolicy,
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
        $connectorManaged = $this->connectorTargets->hasTargets(ExternalConnectorTarget::CAPABILITY_MEDIA);

        if ($connectorManaged) {
            $storeResult = $this->mediaDispatcher->store(new ExternalMediaUpload(
                $objectKey,
                $file->getPathname(),
                $mimeType,
            ));

            if ($storeResult->summary->status() === ExternalConnectorExecutionSummary::STATUS_FAILED) {
                throw new \RuntimeException('Der Upload konnte auf den erforderlichen Medienzielen nicht gespeichert werden.');
            }

            $firstObject = array_values($storeResult->objectsByTarget)[0] ?? null;
            if ($firstObject === null) {
                throw new \RuntimeException('Kein Medienziel hat den Upload gespeichert.');
            }

            $mode = ModuleStorageSetting::MODE_EXTERNAL;
            $location = $firstObject->location;
        } elseif ($mode === ModuleStorageSetting::MODE_EXTERNAL) {
            $setting = $this->settingFor($moduleKey);
            $location = $this->objectStorage->upload(
                $objectKey,
                $file->getPathname(),
                $mimeType,
                $setting?->getExternalBaseUrl(),
            );
        } else {
            $directory = $this->projectDir.'/public/uploads/media/'.$moduleKey;
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException('Der Upload-Ordner konnte nicht erstellt werden.');
            }
            $file->move($directory, $filename);
            $location = '/uploads/media/'.$objectKey;
        }

        $asset = (new MediaAsset())
            ->setModuleKey($moduleKey)
            ->setStorageMode($mode)
            ->setLocation($location)
            ->setOriginalName($originalName)
            ->setTitle(pathinfo($originalName, PATHINFO_FILENAME) ?: $originalName)
            ->setChecksumSha256($checksum)
            ->setMimeType($mimeType)
            ->setFileSize($size === false ? null : $size)
            ->setAltText($altText);

        if ($connectorManaged) {
            $providerByTarget = [];
            foreach ($storeResult->summary->results as $result) {
                $providerByTarget[$result->targetKey] = $result->providerKey;
            }

            foreach ($storeResult->objectsByTarget as $targetKey => $object) {
                $asset->addReplica(
                    (new MediaAssetReplica())
                        ->setTargetKey($targetKey)
                        ->setProviderKey($providerByTarget[$targetKey])
                        ->setObjectKey($object->objectKey)
                        ->setLocation($object->location),
                );
            }

            foreach ($storeResult->summary->results as $result) {
                if ($result->successful || $result->required) {
                    continue;
                }

                $stagedFilename = bin2hex(random_bytes(16)).'.bin';
                $repairDirectory = $this->projectDir.'/var/media-repair';
                if (!is_dir($repairDirectory) && !mkdir($repairDirectory, 0700, true) && !is_dir($repairDirectory)) {
                    continue;
                }
                $stagedPath = $repairDirectory.'/'.$stagedFilename;
                if (!copy($file->getPathname(), $stagedPath)) {
                    continue;
                }
                chmod($stagedPath, 0600);

                $this->entityManager->persist(
                    (new MediaReplicationTask())
                        ->setAsset($asset)
                        ->setTargetKey($result->targetKey)
                        ->setProviderKey($result->providerKey)
                        ->setObjectKey($objectKey)
                        ->setStagedFilename($stagedFilename),
                );
            }
        }

        $this->entityManager->persist($asset);

        return $asset;
    }

    public function storeExternal(string $url, string $moduleKey, ?string $altText = null): MediaAsset
    {
        if ($this->modeFor($moduleKey) !== ModuleStorageSetting::MODE_EXTERNAL) {
            throw new \DomainException('Dieses Modul ist auf interne Speicherung eingestellt. Bitte eine Datei hochladen.');
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $originalName = basename($path) ?: 'Externe Datei';
        $asset = (new MediaAsset())
            ->setModuleKey($moduleKey)
            ->setStorageMode(ModuleStorageSetting::MODE_EXTERNAL)
            ->setLocation($url)
            ->setOriginalName($originalName)
            ->setTitle(pathinfo($originalName, PATHINFO_FILENAME) ?: $originalName)
            ->setAltText($altText);
        $this->entityManager->persist($asset);

        return $asset;
    }

    public function delete(MediaAsset $asset): void
    {
        if (!$asset->getReplicas()->isEmpty()) {
            $objectKeys = [];
            foreach ($asset->getReplicas() as $replica) {
                $objectKeys[$replica->getTargetKey()] = $replica->getObjectKey();
            }

            $summary = $this->mediaDispatcher->delete($objectKeys);
            foreach (array_keys($objectKeys) as $targetKey) {
                $deleted = array_filter(
                    $summary->results,
                    static fn ($result): bool => $result->targetKey === $targetKey && $result->successful,
                );
                if ($deleted === []) {
                    throw new \RuntimeException('Mindestens eine Medienkopie konnte nicht sicher gelöscht werden.');
                }
            }
        } elseif ($asset->isExternal()) {
            if ($asset->getFileSize() !== null) {
                if (!$this->objectStorage->isConfigured()) {
                    throw new \DomainException('Die S3-Zugangsdaten werden benötigt, um diese externe Datei sicher zu löschen.');
                }
                $path = (string) parse_url($asset->getLocation(), PHP_URL_PATH);
                $filename = rawurldecode(basename($path));
                $this->objectStorage->delete($asset->getModuleKey().'/'.$filename);
            }
        } else {
            $location = $asset->getLocation();
            if (str_starts_with($location, '/uploads/media/') && !str_contains($location, '..')) {
                $filePath = $this->projectDir.'/public'.$location;
                if (is_file($filePath) && !unlink($filePath)) {
                    throw new \RuntimeException('Die Datei konnte nicht vom CMS-Server gelöscht werden.');
                }
            }
        }

        $this->entityManager->remove($asset);
    }

    private function settingFor(string $moduleKey): ?ModuleStorageSetting
    {
        return $this->settings->findOneBy(['moduleKey' => $moduleKey]);
    }
}

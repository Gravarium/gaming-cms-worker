<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MediaAsset;
use App\Entity\MediaFolder;
use App\Entity\ModuleStorageSetting;
use App\Form\MediaAssetBatchUploadType;
use App\Repository\MediaAssetRepository;
use App\Service\MediaStorageManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('CMS_STORAGE_MANAGE')]
final class AdminMediaBatchUploadController extends AbstractController
{
    private const MODULES = [
        'branding' => 'Logo und Website-Branding',
        'content' => 'Seiten und News',
        'gaming' => 'Gaming, Gilden und Clans',
        'users' => 'Benutzerbilder',
        'downloads' => 'Downloads und Dokumente',
    ];

    public function __construct(
        private readonly MediaAssetRepository $media,
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaStorageManager $mediaStorage,
    ) {
    }

    #[Route('/admin/storage/media/batch-upload', name: 'app_admin_media_batch_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        $form = $this->createForm(MediaAssetBatchUploadType::class, null, ['modules' => self::MODULES])
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $moduleCandidate = is_array($data) ? ($data['moduleKey'] ?? null) : null;
            $moduleKey = is_string($moduleCandidate) ? $moduleCandidate : '';
            $folderCandidate = is_array($data) ? ($data['folder'] ?? null) : null;
            $folder = $folderCandidate instanceof MediaFolder ? $folderCandidate : null;
            $rawFiles = $form->get('files')->getData();
            $files = [];

            if (!isset(self::MODULES[$moduleKey]) || !is_array($rawFiles)) {
                $form->addError(new FormError('Bitte wähle ein gültiges Zielmodul und mindestens eine Datei.'));
            } else {
                foreach ($rawFiles as $rawFile) {
                    if (!$rawFile instanceof UploadedFile) {
                        $form->addError(new FormError('Eine ausgewählte Datei ist ungültig.'));
                        $files = [];
                        break;
                    }

                    $files[] = $rawFile;
                }

                if ($files === [] || count($files) > 10) {
                    $form->addError(new FormError('Wähle zwischen einer und 10 Dateien aus.'));
                }
            }

            if ($form->isValid()
                && $this->mediaStorage->modeFor($moduleKey) === ModuleStorageSetting::MODE_EXTERNAL
                && !$this->mediaStorage->externalUploadReady()
            ) {
                $form->addError(new FormError('Für dieses Modul ist externer Speicher eingestellt, aber keine Verbindung ist bereit.'));
            }

            if ($form->isValid()) {
                $seen = [];

                foreach ($files as $file) {
                    $size = $file->getSize();
                    $checksum = hash_file('sha256', $file->getPathname());

                    if ($size === false || $checksum === false) {
                        $form->addError(new FormError('Eine Datei konnte vor dem Speichern nicht sicher geprüft werden.'));
                        break;
                    }

                    $duplicateKey = $checksum.':'.$size;
                    if (isset($seen[$duplicateKey])) {
                        $form->addError(new FormError('Die Auswahl enthält dieselbe Datei mehrfach.'));
                        break;
                    }
                    $seen[$duplicateKey] = true;

                    $duplicate = $this->media->findDuplicate($checksum, $size);
                    if ($duplicate !== null) {
                        $form->addError(new FormError('Diese Datei existiert bereits als „'.$duplicate->getTitle().'“.'));
                        break;
                    }
                }
            }

            if ($form->isValid()) {
                $assets = [];

                try {
                    foreach ($files as $file) {
                        $asset = $this->mediaStorage->storeUpload($file, $moduleKey);
                        $asset->setFolder($folder);
                        $assets[] = $asset;
                    }

                    $this->mediaStorage->flushWithRollback(...$assets);
                    $count = count($assets);
                    $label = $count === 1 ? 'Datei wurde' : 'Dateien wurden';
                    $this->addFlash('success', $count.' '.$label.' geprüft und in die Medienbibliothek aufgenommen.');

                    return $this->secureAdminResponse($this->redirectToRoute(
                        'app_admin_storage_index',
                        ['folder' => $folder?->getId()],
                    ));
                } catch (\Throwable $exception) {
                    $this->discardStagedAssets($assets);

                    if (!($exception instanceof \DomainException) && !($exception instanceof \RuntimeException)) {
                        throw $exception;
                    }

                    $form->addError(new FormError($exception->getMessage()));
                }
            }
        }

        $response = $this->render('admin/storage/batch_upload.html.twig', [
            'form' => $form,
            'externalStorageReady' => $this->mediaStorage->externalUploadReady(),
        ]);

        return $this->secureAdminResponse($response);
    }

    /** @param list<MediaAsset> $assets */
    private function discardStagedAssets(array $assets): void
    {
        try {
            $this->mediaStorage->discardUncommitted(...$assets);
        } finally {
            if ($this->entityManager->isOpen()) {
                foreach ($assets as $asset) {
                    if ($this->entityManager->contains($asset)) {
                        $this->entityManager->remove($asset);
                    }
                }
            }
        }
    }

    private function secureAdminResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}

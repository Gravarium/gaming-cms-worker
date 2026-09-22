<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MediaAsset;
use App\Entity\MediaFolder;
use App\Entity\ModuleStorageSetting;
use App\Form\MediaAssetMetadataType;
use App\Form\MediaAssetReplacementType;
use App\Form\MediaAssetUploadType;
use App\Form\MediaFolderType;
use App\Form\ModuleStorageSettingType;
use App\Repository\MediaAssetRepository;
use App\Repository\MediaFolderRepository;
use App\Repository\ModuleStorageSettingRepository;
use App\Service\MediaAssetUsageResolver;
use App\Service\MediaStorageManager;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/storage')]
#[IsGranted('CMS_STORAGE_MANAGE')]
final class AdminStorageController extends AbstractController
{
    private const MODULES = [
        'branding' => 'Logo und Website-Branding',
        'content' => 'Seiten und News',
        'gaming' => 'Gaming, Gilden und Clans',
        'video' => 'Videos',
        'users' => 'Benutzerbilder',
        'downloads' => 'Downloads und Dokumente',
    ];

    public function __construct(
        private readonly ModuleStorageSettingRepository $settings,
        private readonly MediaAssetRepository $media,
        private readonly MediaFolderRepository $folders,
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaStorageManager $mediaStorage,
        private readonly MediaAssetUsageResolver $usageResolver,
        private readonly SluggerInterface $slugger,
    ) {
    }

    #[Route('', name: 'app_admin_storage_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $configured = [];
        foreach ($this->settings->findAll() as $setting) { $configured[$setting->getModuleKey()] = $setting; }

        $module = $request->query->getString('module');
        $module = isset(self::MODULES[$module]) ? $module : null;
        $storage = $request->query->getString('storage');
        $storage = in_array($storage, [ModuleStorageSetting::MODE_INTERNAL, ModuleStorageSetting::MODE_EXTERNAL], true) ? $storage : null;
        $type = $request->query->getString('type');
        $type = in_array($type, ['image', 'video', 'other'], true) ? $type : null;
        $query = trim($request->query->getString('q'));
        $folderFilter = $request->query->getString('folder');
        $folder = ctype_digit($folderFilter) ? $this->folders->find((int) $folderFilter) : null;
        if (ctype_digit($folderFilter) && $folder === null) {
            throw $this->createNotFoundException('Der Medienordner existiert nicht.');
        }
        $withoutFolder = $folderFilter === 'none';

        $assets = $this->media->searchLibrary($query, $module, $storage, $type, $folder, $withoutFolder);
        $usages = [];
        $totalSize = 0;
        foreach ($assets as $asset) {
            /** @var int $assetId */
            $assetId = $asset->getId();
            $usages[$assetId] = $this->usageResolver->usages($asset);
            $totalSize += $asset->getFileSize() ?? 0;
        }

        return $this->render('admin/storage/index.html.twig', [
            'modules' => self::MODULES,
            'configured' => $configured,
            'folders' => $this->folders->ordered(),
            'assets' => $assets,
            'assetUsages' => $usages,
            'totalSize' => $totalSize,
            'filters' => ['q' => $query, 'module' => $module, 'storage' => $storage, 'type' => $type, 'folder' => $folderFilter],
            'externalStorageReady' => $this->mediaStorage->externalUploadReady(),
        ]);
    }

    #[Route('/media/upload', name: 'app_admin_media_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        $form = $this->createForm(MediaAssetUploadType::class, null, ['modules' => self::MODULES])->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $moduleKey = (string) ($data['moduleKey'] ?? '');
            $file = $data['file'] ?? null;
            if (!isset(self::MODULES[$moduleKey]) || !$file instanceof UploadedFile) {
                $form->addError(new FormError('Bitte wähle ein Zielmodul und eine gültige Datei.'));
            } elseif ($this->mediaStorage->modeFor($moduleKey) === ModuleStorageSetting::MODE_EXTERNAL && !$this->mediaStorage->externalUploadReady()) {
                $form->addError(new FormError('Für dieses Modul ist externer Speicher eingestellt, aber keine Verbindung ist bereit.'));
            } else {
                $checksum = hash_file('sha256', $file->getPathname());
                $duplicate = $checksum === false ? null : $this->media->findDuplicate($checksum, (int) $file->getSize());
                if ($duplicate !== null) {
                    $form->addError(new FormError('Diese Datei existiert bereits als „'.$duplicate->getTitle().'“.'));
                }
            }

            if ($form->isValid()) {
                try {
                    $asset = $this->mediaStorage->storeUpload($file, $moduleKey, $this->optional($data['altText'] ?? null));
                    try {
                        $asset
                            ->setTitle($this->optional($data['title'] ?? null) ?? $asset->getTitle())
                            ->setCaption($this->optional($data['caption'] ?? null))
                            ->setTagsText($this->optional($data['tags'] ?? null))
                            ->setFolder(($data['folder'] ?? null) instanceof MediaFolder ? $data['folder'] : null);
                        $this->mediaStorage->flushWithRollback($asset);
                    } catch (\DomainException|\RuntimeException $exception) {
                        $this->mediaStorage->discardUncommitted($asset);
                        throw $exception;
                    }
                    $this->addFlash('success', 'Die Datei wurde geprüft und in die Medienbibliothek aufgenommen.');

                    return $this->redirectToRoute('app_admin_storage_index', ['folder' => $asset->getFolder()?->getId()]);
                } catch (\DomainException|\RuntimeException $exception) {
                    $form->addError(new FormError($exception->getMessage()));
                }
            }
        }

        return $this->render('admin/storage/upload.html.twig', ['form' => $form, 'externalStorageReady' => $this->mediaStorage->externalUploadReady()]);
    }

    #[Route('/media/{id}/edit', name: 'app_admin_media_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editMedia(MediaAsset $asset, Request $request): Response
    {
        $this->ensureActiveAsset($asset);
        $form = $this->createForm(MediaAssetMetadataType::class, $asset)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Metadaten und Ordner wurden gespeichert.');
            return $this->redirectToRoute('app_admin_storage_index', ['folder' => $asset->getFolder()?->getId()]);
        }

        return $this->render('admin/storage/media_edit.html.twig', [
            'asset' => $asset,
            'form' => $form,
            'usages' => $this->usageResolver->usages($asset),
        ]);
    }

    #[Route('/media/{id}/replace', name: 'app_admin_media_replace', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function replace(MediaAsset $asset, Request $request): Response
    {
        $this->ensureActiveAsset($asset);
        $form = $this->createForm(MediaAssetReplacementType::class)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();
            if (!$file instanceof UploadedFile) { throw new \LogicException('Uploaded file expected.'); }
            try {
                $replacement = $this->mediaStorage->storeUpload($file, $asset->getModuleKey(), $asset->getAltText());
                try {
                    $replacement->setTitle($asset->getTitle())->setCaption($asset->getCaption())->setTags($asset->getTags())->setFolder($asset->getFolder());
                    $changed = $this->usageResolver->replaceUsages($asset, $replacement);
                    $this->mediaStorage->flushWithRollback($replacement);
                } catch (\DomainException|\RuntimeException $exception) {
                    $this->mediaStorage->discardUncommitted($replacement);
                    throw $exception;
                }
                $this->addFlash('success', 'Neue Datei gespeichert und '.$changed.' bekannte Verwendungen sicher umgestellt. Die alte Datei bleibt als Rückfall erhalten.');
                return $this->redirectToRoute('app_admin_media_edit', ['id' => $replacement->getId()]);
            } catch (\DomainException|\RuntimeException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/storage/media_replace.html.twig', ['asset' => $asset, 'form' => $form]);
    }

    #[Route('/media/bulk', name: 'app_admin_media_bulk', methods: ['POST'])]
    public function bulk(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('bulk-media', $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $ids = array_values(array_unique(array_filter((array) $request->request->all('assets'), static fn ($id): bool => ctype_digit((string) $id))));
        $assets = $ids === [] ? [] : array_values(array_filter(
            $this->media->findBy(['id' => array_map('intval', $ids)]),
            static fn (MediaAsset $asset): bool => !$asset->isDeletionPending(),
        ));
        $action = $request->request->getString('bulk_action');
        $changed = 0;
        $failed = 0;

        if ($action === 'move') {
            $folderId = $request->request->getString('target_folder');
            if ($folderId !== '' && !ctype_digit($folderId)) {
                $this->addFlash('error', 'Der Zielordner ist ungültig.');
                return $this->redirectToRoute('app_admin_storage_index');
            }
            $folder = $folderId === '' ? null : $this->folders->find((int) $folderId);
            if ($folderId !== '' && $folder === null) {
                $this->addFlash('error', 'Der Zielordner existiert nicht mehr.');
                return $this->redirectToRoute('app_admin_storage_index');
            }
            foreach ($assets as $asset) { $asset->setFolder($folder); ++$changed; }
        } elseif ($action === 'delete') {
            foreach ($assets as $asset) {
                if ($this->usageResolver->isUsed($asset)) { ++$failed; continue; }
                try {
                    $this->mediaStorage->delete($asset);
                    ++$changed;
                } catch (\DomainException|\RuntimeException) {
                    ++$failed;
                }
            }
        } else {
            $this->addFlash('error', 'Bitte eine gültige Mehrfachaktion wählen.');
            return $this->redirectToRoute('app_admin_storage_index');
        }

        if (!$this->entityManager->isOpen()) {
            $this->addFlash('error', 'Die Mehrfachaktion wurde nach einem Datenbankfehler abgebrochen; offene Medienlöschungen bleiben reparierbar vorgemerkt.');

            return $this->redirectToRoute('app_admin_storage_index');
        }

        try {
            $this->entityManager->flush();
        } catch (\Throwable) {
            $this->addFlash('error', 'Die Mehrfachaktion konnte nicht sicher in der Datenbank bestätigt werden.');

            return $this->redirectToRoute('app_admin_storage_index');
        }

        if ($failed > 0) {
            $this->addFlash('error', $failed.' Medien konnten nicht verarbeitet werden oder werden noch verwendet.');
        }
        $this->addFlash('success', $changed.' Medien wurden verarbeitet.');

        return $this->redirectToRoute('app_admin_storage_index');
    }

    #[Route('/media/{id}/delete', name: 'app_admin_media_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(MediaAsset $asset, Request $request): Response
    {
        $this->ensureActiveAsset($asset);
        if (!$this->isCsrfTokenValid('delete-media-'.$asset->getId(), $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $usages = $this->usageResolver->usages($asset);
        if ($usages !== []) {
            $this->addFlash('error', 'Die Datei wird noch verwendet als: '.implode(', ', $usages).'.');
            return $this->redirectToRoute('app_admin_storage_index');
        }
        try {
            $this->mediaStorage->delete($asset);
            $this->addFlash('success', 'Die Datei wurde gelöscht.');
        } catch (\DomainException|\RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('app_admin_storage_index');
    }

    #[Route('/folders/new', name: 'app_admin_media_folder_new', methods: ['GET', 'POST'])]
    public function newFolder(Request $request): Response
    {
        return $this->folderForm(new MediaFolder(), $request, 'Medienordner anlegen', 'Ordner anlegen');
    }

    #[Route('/folders/{id}/edit', name: 'app_admin_media_folder_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editFolder(MediaFolder $folder, Request $request): Response
    {
        return $this->folderForm($folder, $request, 'Medienordner bearbeiten', 'Ordner speichern');
    }

    #[Route('/folders/{id}/delete', name: 'app_admin_media_folder_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteFolder(MediaFolder $folder, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-media-folder-'.$folder->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$folder->getAssets()->isEmpty() || $this->folders->count(['parent' => $folder]) > 0) {
            $this->addFlash('error', 'Der Ordner kann erst gelöscht werden, wenn er weder Medien noch Unterordner enthält.');

            return $this->redirectToRoute('app_admin_storage_index');
        }

        try {
            $this->entityManager->remove($folder);
            $this->entityManager->flush();
            $this->addFlash('success', 'Der Medienordner wurde gelöscht.');
        } catch (ForeignKeyConstraintViolationException) {
            $this->addFlash('error', 'Der Ordner wurde parallel verändert und ist nicht mehr leer. Bitte erneut prüfen.');
        }

        return $this->redirectToRoute('app_admin_storage_index');
    }

    #[Route('/{moduleKey}', name: 'app_admin_storage_edit', methods: ['GET', 'POST'])]
    public function edit(string $moduleKey, Request $request): Response
    {
        if (!isset(self::MODULES[$moduleKey])) { throw $this->createNotFoundException(); }
        $setting = $this->settings->findOneBy(['moduleKey' => $moduleKey]) ?? (new ModuleStorageSetting())->setModuleKey($moduleKey);
        $form = $this->createForm(ModuleStorageSettingType::class, $setting)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($setting->getStorageMode() === ModuleStorageSetting::MODE_INTERNAL) { $setting->setExternalBaseUrl(null); }
            if ($setting->getId() === null) { $this->entityManager->persist($setting); }
            $this->entityManager->flush();
            $this->addFlash('success', 'Das Speicherziel wurde gespeichert.');
            return $this->redirectToRoute('app_admin_storage_index');
        }

        return $this->render('admin/storage/form.html.twig', [
            'form' => $form,
            'moduleLabel' => self::MODULES[$moduleKey],
            'externalStorageReady' => $this->mediaStorage->externalUploadReady(),
        ]);
    }

    private function folderForm(MediaFolder $folder, Request $request, string $heading, string $submitLabel): Response
    {
        $form = $this->createForm(MediaFolderType::class, $folder, ['current_folder' => $folder])->handleRequest($request);

        if ($form->isSubmitted()) {
            $parent = $form->get('parent')->getData();
            try {
                $folder->setParent($parent instanceof MediaFolder ? $parent : null);
            } catch (\DomainException $exception) {
                $form->get('parent')->addError(new FormError($exception->getMessage()));
            }

            if ($form->isValid()) {
                $folder->setSlug($this->uniqueFolderSlug($folder));
                if ($folder->getId() === null) {
                    $this->entityManager->persist($folder);
                }
                $this->entityManager->flush();
                $this->addFlash('success', 'Der Medienordner wurde gespeichert.');

                return $this->redirectToRoute('app_admin_storage_index', ['folder' => $folder->getId()]);
            }
        }

        $response = $this->render('admin/storage/folder_form.html.twig', [
            'form' => $form,
            'heading' => $heading,
            'submitLabel' => $submitLabel,
        ]);
        if ($form->isSubmitted()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    private function uniqueFolderSlug(MediaFolder $folder): string
    {
        $base = mb_strtolower($this->slugger->slug($folder->getName())->toString()) ?: 'ordner';
        $slug = $base;
        $suffix = 2;

        while (($existing = $this->folders->findOneBy(['slug' => $slug])) !== null && $existing->getId() !== $folder->getId()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function ensureActiveAsset(MediaAsset $asset): void
    {
        if ($asset->isDeletionPending()) {
            throw new ConflictHttpException('Diese Datei ist bereits zur sicheren Löschung vorgemerkt und kann nicht mehr verändert werden.');
        }
    }

    private function optional(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}

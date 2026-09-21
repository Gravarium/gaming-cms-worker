<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MediaAsset;
use App\Entity\ModuleStorageSetting;
use App\Form\SiteSettingsType;
use App\Repository\SiteSettingsRepository;
use App\Service\MediaStorageManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/settings')]
#[IsGranted('CMS_SETTINGS_MANAGE')]
final class AdminSettingsController extends AbstractController
{
    private const MODULE_KEY = 'branding';

    public function __construct(
        private readonly SiteSettingsRepository $settingsRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MediaStorageManager $mediaStorage,
    ) {
    }

    #[Route('', name: 'app_admin_settings', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $settings = $this->settingsRepository->current();
        $form = $this->createForm(SiteSettingsType::class, $settings)->handleRequest($request);
        $mode = $this->mediaStorage->modeFor(self::MODULE_KEY);
        $externalReady = $this->mediaStorage->externalUploadReady();

        if ($form->isSubmitted()) {
            $logo = $form->get('logoFile')->getData();
            $logoUrl = trim((string) $form->get('logoUrl')->getData());
            $favicon = $form->get('faviconFile')->getData();
            $faviconUrl = trim((string) $form->get('faviconUrl')->getData());

            if ($mode === ModuleStorageSetting::MODE_INTERNAL && ($logoUrl !== '' || $faviconUrl !== '')) {
                $form->addError(new FormError('Branding ist auf interne Speicherung eingestellt. Bitte Dateien hochladen oder das Speicherziel ändern.'));
            }
            if ($mode === ModuleStorageSetting::MODE_EXTERNAL
                && !$externalReady
                && ($logo instanceof UploadedFile || $favicon instanceof UploadedFile)
            ) {
                $form->addError(new FormError('Der automatische externe Upload ist noch nicht konfiguriert. Verwende vorläufig eine externe URL.'));
            }

            if ($form->isValid()) {
                /** @var list<MediaAsset> $newAssets */
                $newAssets = [];
                try {
                    if ($logo instanceof UploadedFile) {
                        $asset = $this->mediaStorage->storeUpload($logo, self::MODULE_KEY, 'Website-Logo');
                        $newAssets[] = $asset;
                        $settings->setLogoPath($asset->getLocation());
                    } elseif ($logoUrl !== '') {
                        $asset = $this->mediaStorage->storeExternal($logoUrl, self::MODULE_KEY, 'Website-Logo');
                        $newAssets[] = $asset;
                        $settings->setLogoPath($asset->getLocation());
                    }

                    if ($favicon instanceof UploadedFile) {
                        $asset = $this->mediaStorage->storeUpload($favicon, self::MODULE_KEY, 'Favicon');
                        $newAssets[] = $asset;
                        $settings->setFaviconPath($asset->getLocation());
                    } elseif ($faviconUrl !== '') {
                        $asset = $this->mediaStorage->storeExternal($faviconUrl, self::MODULE_KEY, 'Favicon');
                        $newAssets[] = $asset;
                        $settings->setFaviconPath($asset->getLocation());
                    }

                    if ($settings->getId() === null) {
                        $this->entityManager->persist($settings);
                    }
                    $this->mediaStorage->flushWithRollback(...$newAssets);
                    $this->addFlash('success', 'Die Website-Einstellungen wurden gespeichert.');

                    return $this->redirectToRoute('app_admin_settings');
                } catch (\DomainException|\RuntimeException $exception) {
                    $this->mediaStorage->discardUncommitted(...$newAssets);
                    $form->addError(new FormError($exception->getMessage()));
                }
            }
        }

        return $this->render('admin/settings/edit.html.twig', [
            'form' => $form,
            'settings' => $settings,
            'storageMode' => $mode,
            'externalStorageReady' => $externalReady,
        ]);
    }
}

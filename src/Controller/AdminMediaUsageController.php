<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MediaAsset;
use App\Media\MediaAssetUsageBrowser;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/storage/media')]
#[IsGranted('CMS_STORAGE_MANAGE')]
final class AdminMediaUsageController extends AbstractController
{
    public function __construct(
        private readonly MediaAssetUsageBrowser $usageBrowser,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/{id}/usage', name: 'app_admin_media_usage', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(MediaAsset $asset): Response
    {
        if (!$this->modules->isEnabled('media')) {
            throw $this->createNotFoundException();
        }

        $canManageContent = $this->isGranted('CMS_CONTENT_MANAGE') && $this->modules->isEnabled('content');
        $contentReferences = $canManageContent
            ? $this->usageBrowser->contentReferences($asset)
            : ['items' => [], 'truncated' => false];

        $response = $this->render('admin/storage/media_usage.html.twig', [
            'asset' => $asset,
            'contentReferences' => $contentReferences,
            'layoutReferences' => $this->usageBrowser->layoutReferences($asset, $canManageContent),
            'canManageContent' => $canManageContent,
            'canManageLayouts' => $this->isGranted('CMS_SETTINGS_MANAGE'),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}

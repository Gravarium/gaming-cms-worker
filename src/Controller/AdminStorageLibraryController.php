<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MediaAsset;
use App\Entity\MediaFolder;
use App\Repository\MediaAssetRepository;
use App\Repository\MediaFolderRepository;
use App\Service\MediaAssetUsageResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/storage/library/all')]
#[IsGranted('CMS_STORAGE_MANAGE')]
final class AdminStorageLibraryController extends AbstractController
{
    private const PAGE_SIZE = 24;

    /** @var array<string, string> */
    private const MODULES = [
        'branding' => 'Logo und Website-Branding',
        'content' => 'Seiten und News',
        'gaming' => 'Gaming, Gilden und Clans',
        'video' => 'Videos',
        'users' => 'Benutzerbilder',
        'downloads' => 'Downloads und Dokumente',
    ];

    public function __construct(
        private readonly MediaAssetRepository $media,
        private readonly MediaFolderRepository $folders,
        private readonly MediaAssetUsageResolver $usageResolver,
    ) {
    }

    #[Route('', name: 'app_admin_storage_library', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $moduleInput = $request->query->getString('module');
        $module = isset(self::MODULES[$moduleInput]) ? $moduleInput : null;

        $storageInput = $request->query->getString('storage');
        $storage = in_array($storageInput, ['internal', 'external'], true) ? $storageInput : null;

        $typeInput = $request->query->getString('type');
        $type = in_array($typeInput, ['image', 'video', 'other'], true) ? $typeInput : null;

        $query = mb_substr(trim($request->query->getString('q')), 0, 160);

        $folderFilter = $request->query->getString('folder');
        if ($folderFilter !== '' && $folderFilter !== 'none' && !ctype_digit($folderFilter)) {
            throw new BadRequestHttpException('Der Medienordner ist ungültig.');
        }

        $folder = ctype_digit($folderFilter) ? $this->folders->find((int) $folderFilter) : null;
        if (ctype_digit($folderFilter) && $folder === null) {
            throw $this->createNotFoundException('Der Medienordner existiert nicht.');
        }
        $withoutFolder = $folderFilter === 'none';

        $pageValue = $request->query->get('page', '1');
        if (!ctype_digit($pageValue) || (int) $pageValue < 1) {
            throw new BadRequestHttpException('Die Seitennummer ist ungültig.');
        }
        $requestedPage = (int) $pageValue;

        $result = $this->media->searchLibraryPage(
            $query,
            $module,
            $storage,
            $type,
            $folder,
            $withoutFolder,
            $requestedPage,
            self::PAGE_SIZE,
        );

        $assetUsages = [];
        $totalSize = 0;
        foreach ($result['assets'] as $asset) {
            $assetId = $asset->getId();
            if ($assetId !== null) {
                $assetUsages[$assetId] = $this->usageResolver->usages($asset);
            }
            $totalSize += $asset->getFileSize() ?? 0;
        }

        $response = $this->render('admin/storage/library.html.twig', [
            'modules' => self::MODULES,
            'folders' => $this->folders->ordered(),
            'assets' => $result['assets'],
            'assetUsages' => $assetUsages,
            'total' => $result['total'],
            'totalSize' => $totalSize,
            'page' => $result['page'],
            'pageCount' => $result['pageCount'],
            'filters' => [
                'q' => $query,
                'module' => $module,
                'storage' => $storage,
                'type' => $type,
                'folder' => $folderFilter,
            ],
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}

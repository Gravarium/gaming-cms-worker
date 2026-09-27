<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentEditor\OwnedMediaReferenceGateway;
use App\Module\CmsModuleManager;
use App\Repository\ContentMediaPickerRepository;
use App\Security\CmsPermission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/content/media-picker')]
#[IsGranted(CmsPermission::CONTENT)]
final class AdminContentMediaPickerController extends AbstractController
{
    public function __construct(
        private readonly ContentMediaPickerRepository $media,
        private readonly OwnedMediaReferenceGateway $references,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('', name: 'app_admin_content_media_picker', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        if (!$this->modules->isEnabled('content')) {
            return $this->privateJson(['error' => 'Nicht gefunden.'], Response::HTTP_NOT_FOUND);
        }

        $parameters = $request->query->all();
        $rawQuery = $parameters['q'] ?? '';
        $rawPage = $parameters['page'] ?? '1';
        if (!is_string($rawQuery) || !is_string($rawPage)) {
            return $this->privateJson(['error' => 'Ungültige Suchparameter.'], Response::HTTP_BAD_REQUEST);
        }

        $query = trim($rawQuery);
        if (preg_match('//u', $query) !== 1
            || preg_match('/[\x00-\x1F\x7F]/u', $query) === 1
            || mb_strlen($query) > 100
            || preg_match('/^[1-9][0-9]{0,8}$/D', $rawPage) !== 1
        ) {
            return $this->privateJson(['error' => 'Ungültige Suchparameter.'], Response::HTTP_BAD_REQUEST);
        }

        $requestedPage = (int) $rawPage;
        $total = $this->media->countSelectable($query);
        $pageCount = max(1, (int) ceil($total / ContentMediaPickerRepository::PAGE_SIZE));
        $page = min($requestedPage, $pageCount);

        /** @var list<array{id:int,title:string,originalName:string,altText:string,url:string}> $items */
        $items = [];
        foreach ($this->media->findSelectablePage($query, $page) as $asset) {
            $assetId = $asset->getId();
            if ($assetId === null) {
                continue;
            }

            $reference = $this->references->resolve($assetId);
            if ($reference === null) {
                continue;
            }

            $items[] = [
                'id' => $assetId,
                'title' => $reference['title'],
                'originalName' => $asset->getOriginalName(),
                'altText' => $asset->getAltText() ?? '',
                'url' => $reference['url'],
            ];
        }

        return $this->privateJson([
            'query' => $query,
            'page' => $page,
            'pages' => $pageCount,
            'total' => $total,
            'items' => $items,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function privateJson(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}

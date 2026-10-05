<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\VideoWorkspace\VideoSource;
use App\Video\Discovery\VideoModuleAvailability;
use App\VideoProviderAudit\SourceAudit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/video-provider-audit')]
#[IsGranted('CMS_VIDEO_MANAGE')]
final class VideoProviderAuditController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VideoModuleAvailability $module,
        private readonly SourceAudit $audit,
    ) {}

    #[Route('', name: 'app_admin_video_provider_audit', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        if (!$this->module->enabled()) {
            throw new NotFoundHttpException();
        }

        $pagination = $this->pagination($request);
        if ($pagination === null) {
            return $this->response(['error' => 'Ungültige Paginierung.'], 400);
        }

        $limit = $pagination['limit'];
        /** @var list<VideoSource> $sources */
        $sources = $this->em->getRepository(VideoSource::class)
            ->createQueryBuilder('source')
            ->andWhere('source.id > :after')
            ->setParameter('after', $pagination['after'])
            ->orderBy('source.id', 'ASC')
            ->setMaxResults($limit + 1)
            ->getQuery()
            ->getResult();

        $hasMore = count($sources) > $limit;
        if ($hasMore) {
            array_pop($sources);
        }

        $items = [];
        foreach ($sources as $source) {
            $classification = $this->audit->classify($source, $request->getHost());
            $items[] = [
                'source_id' => (int) $source->getId(),
                'provider' => $source->getProvider(),
                'enabled' => $source->isEnabled(),
                'authorized' => $source->isAuthorized(),
                'status' => $classification['status'],
                'mode' => $classification['mode'],
            ];
        }

        $nextCursor = null;
        if ($hasMore && $sources !== []) {
            $last = $sources[array_key_last($sources)];
            $nextCursor = (int) $last->getId();
        }

        return $this->response([
            'items' => $items,
            'limit' => $limit,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'note' => 'Die Klassifikation prüft nur lokal registrierte URL-Formate. Anbieter werden nicht kontaktiert; Verfügbarkeit und Wiedergabe werden nicht geprüft.',
        ]);
    }

    /** @return array{after:int, limit:int}|null */
    private function pagination(Request $request): ?array
    {
        $params = $request->query->all();
        $after = $params['after'] ?? '0';
        $limit = $params['limit'] ?? '50';

        if (!is_string($after)
            || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $after) !== 1
            || (int) $after > 2147483647
            || !is_string($limit)
            || preg_match('/\A[1-9][0-9]{0,2}\z/D', $limit) !== 1
            || (int) $limit > 100
        ) {
            return null;
        }

        return ['after' => (int) $after, 'limit' => (int) $limit];
    }

    /** @param array<string,mixed> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        $response = $this->json($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}

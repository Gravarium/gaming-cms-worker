<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\VideoWorkspace\VideoSource;
use App\Video\Discovery\VideoModuleAvailability;
use App\VideoProviderAudit\SourceAudit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/video-provider-audit/page')]
#[IsGranted('CMS_VIDEO_MANAGE')]
final class VideoProviderAuditPageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VideoModuleAvailability $module,
        private readonly SourceAudit $audit,
    ) {}

    #[Route('', name: 'app_admin_video_provider_audit_page', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->module->enabled()) {
            throw new NotFoundHttpException();
        }

        $pagination = $this->pagination($request);
        if ($pagination === null) {
            return $this->secure(new Response(
                'Ungültige Paginierung.',
                Response::HTTP_BAD_REQUEST,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            ));
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
                'sourceId' => (int) $source->getId(),
                'provider' => $source->getProvider(),
                'enabled' => $source->isEnabled(),
                'authorized' => $source->isAuthorized(),
                'status' => $classification['status'],
                'mode' => $classification['mode'],
            ];
        }

        $nextCursor = null;
        if ($hasMore && $sources !== []) {
            $nextCursor = $items[array_key_last($items)]['sourceId'];
        }

        $response = $this->render('video_provider_audit/index.html.twig', [
            'items' => $items,
            'limit' => $limit,
            'nextCursor' => $nextCursor,
            'hasMore' => $hasMore,
        ]);

        return $this->secure($response);
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

    private function secure(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}

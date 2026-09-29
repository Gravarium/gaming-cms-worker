<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentOperations\EditorialWorkQueue;
use App\Entity\ContentEntry;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminContentOperationsController extends AbstractController
{
    public function __construct(
        private readonly EditorialWorkQueue $queue,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/admin/content/operations', name: 'app_admin_content_operations', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->modules->isEnabled('content')) {
            throw $this->createNotFoundException('Das Content-Modul ist deaktiviert.');
        }

        $titleQuery = trim($this->stringParameter($request, 'q'));
        if (mb_strlen($titleQuery) > EditorialWorkQueue::MAX_TITLE_QUERY_LENGTH) {
            throw new BadRequestHttpException('Die Titelsuche ist zu lang.');
        }

        $status = $this->optionalChoice($this->stringParameter($request, 'status'), [
            ContentEntry::STATUS_DRAFT,
            ContentEntry::STATUS_REVIEW,
            ContentEntry::STATUS_SCHEDULED,
            ContentEntry::STATUS_PUBLISHED,
        ], 'status');
        $type = $this->optionalChoice($this->stringParameter($request, 'type'), [
            ContentEntry::TYPE_NEWS,
            ContentEntry::TYPE_PAGE,
        ], 'type');
        $kind = $this->optionalChoice($this->stringParameter($request, 'kind'), array_keys(EditorialWorkQueue::WORK_TYPE_LABELS), 'kind');

        $pageValue = $this->stringParameter($request, 'page', '1');
        if (preg_match('/^[1-9][0-9]{0,3}$/D', $pageValue) !== 1) {
            throw new BadRequestHttpException('Die Seite muss eine positive Zahl sein.');
        }
        $page = (int) $pageValue;
        if ($page > EditorialWorkQueue::MAX_PAGE) {
            throw new BadRequestHttpException('Die angeforderte Seite liegt außerhalb des zulässigen Bereichs.');
        }

        $queue = $this->queue->paginate($status, $type, $kind, $titleQuery, $page, new \DateTimeImmutable());

        $response = $this->render('admin/content_operations/index.html.twig', [
            'queue' => $queue,
            'filters' => ['q' => $titleQuery, 'status' => $status, 'type' => $type, 'kind' => $kind],
            'work_type_labels' => EditorialWorkQueue::WORK_TYPE_LABELS,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }

    private function stringParameter(Request $request, string $name, string $default = ''): string
    {
        $value = $request->query->all()[$name] ?? $default;
        if (!is_string($value)) {
            throw new BadRequestHttpException(sprintf('Der Parameter "%s" muss ein einzelner Textwert sein.', $name));
        }

        return $value;
    }

    /**
     * @param list<string> $choices
     */
    private function optionalChoice(string $value, array $choices, string $name): ?string
    {
        if ($value === '') {
            return null;
        }
        if (!in_array($value, $choices, true)) {
            throw new BadRequestHttpException(sprintf('Ungültiger Wert für "%s".', $name));
        }

        return $value;
    }
}

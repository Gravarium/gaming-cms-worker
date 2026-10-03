<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentEditor\ContentBlockPolicy;
use App\Entity\ContentEntry;
use App\Module\CmsModuleManager;
use App\NewsEditor\NewsReadinessReport;
use App\NewsEditor\RichDocument;
use App\Security\CmsPermission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/news-editor')]
#[IsGranted(CmsPermission::CONTENT)]
final class AdminNewsReadinessController extends AbstractController
{
    private const MAX_REQUEST_BYTES = 131072;

    public function __construct(
        private readonly CmsModuleManager $modules,
        private readonly ContentBlockPolicy $policy,
        private readonly NewsReadinessReport $report,
    ) {
    }

    #[Route('/{id}/readiness', name: 'app_admin_news_editor_readiness', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function analyze(ContentEntry $entry, Request $request): JsonResponse
    {
        if (!$this->modules->isEnabled('content') || $entry->getType() !== ContentEntry::TYPE_NEWS) {
            return $this->privateJson(['error' => 'News-Artikel nicht gefunden.'], 404);
        }
        if (!in_array($entry->getStatus(), [ContentEntry::STATUS_DRAFT, ContentEntry::STATUS_REVIEW], true)) {
            return $this->privateJson(['error' => 'Dieser Artikel kann hier nicht geprüft werden.'], 403);
        }
        if (!$this->isCsrfTokenValid('news-editor-'.$entry->getId(), (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->privateJson(['error' => 'Ungültige Sicherheitsprüfung.'], 403);
        }

        $contentLength = $request->headers->get('Content-Length');
        if (is_string($contentLength) && ctype_digit($contentLength) && (strlen($contentLength) > 6 || (int) $contentLength > self::MAX_REQUEST_BYTES)) {
            return $this->privateJson(['error' => 'Audit-Anfrage ist zu groß.'], 413);
        }

        $content = $request->getContent(true);
        $body = is_resource($content)
            ? stream_get_contents($content, self::MAX_REQUEST_BYTES + 1)
            : $content;
        if (!is_string($body)) {
            return $this->privateJson(['error' => 'Audit-Anfrage konnte nicht gelesen werden.'], 400);
        }
        if (strlen($body) > self::MAX_REQUEST_BYTES) {
            return $this->privateJson(['error' => 'Audit-Anfrage ist zu groß.'], 413);
        }

        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || array_keys($payload) !== ['document'] || !is_string($payload['document'])
                || !str_starts_with($payload['document'], RichDocument::PREFIX)) {
                throw new \InvalidArgumentException('Ungültige Audit-Anfrage.');
            }

            $normalized = $this->policy->normalizeForStorage($payload['document']);
            return $this->privateJson($this->report->analyze($normalized, $entry));
        } catch (\JsonException|\InvalidArgumentException) {
            return $this->privateJson(['error' => 'Ungültige Audit-Anfrage.'], 422);
        }
    }

    /** @param array<string,mixed> $data */
    private function privateJson(array $data, int $status = 200): JsonResponse
    {
        $response = $this->json($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentQuality\ContentQualityAudit;
use App\Entity\ContentEntry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/content/quality', name: 'app_admin_content_quality_')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminContentQualityController extends AbstractController
{
    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'all' => 'Alle Status',
        ContentEntry::STATUS_DRAFT => 'Entwurf',
        ContentEntry::STATUS_REVIEW => 'In Prüfung',
        ContentEntry::STATUS_SCHEDULED => 'Geplant',
        ContentEntry::STATUS_PUBLISHED => 'Veröffentlicht',
        ContentEntry::STATUS_ARCHIVED => 'Archiviert',
    ];

    /** @var array<string, string> */
    private const TYPE_LABELS = [
        'all' => 'Alle Typen',
        ContentEntry::TYPE_NEWS => 'News',
        ContentEntry::TYPE_PAGE => 'Seite',
    ];

    public function __construct(private readonly ContentQualityAudit $audit)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        try {
            $filters = $this->parseFilters($request);
            $report = $this->audit->report(
                $filters['status'],
                $filters['type'],
                $filters['issue'],
                $filters['q'],
                $filters['page'],
            );
        } catch (\InvalidArgumentException) {
            return $this->privateResponse(new Response('Ungültige Berichtsfilter.', Response::HTTP_BAD_REQUEST));
        }

        if ($filters['page'] > $report['pages']) {
            return $this->privateResponse(new Response('Berichtsseite nicht gefunden.', Response::HTTP_NOT_FOUND));
        }

        $response = $this->render('@content_quality/index.html.twig', [
            'report' => $report,
            'filters' => [
                'status' => $filters['status'],
                'type' => $filters['type'],
                'issue' => $filters['issue'],
                'q' => $filters['q'],
            ],
            'statusLabels' => self::STATUS_LABELS,
            'typeLabels' => self::TYPE_LABELS,
            'issueLabels' => ['all' => 'Alle Probleme'] + ContentQualityAudit::ISSUE_LABELS,
        ]);

        return $this->privateResponse($response);
    }

    /**
     * @return array{status: string, type: string, issue: string, q: string, page: int}
     */
    private function parseFilters(Request $request): array
    {
        $query = $request->query->all();
        $status = $this->selectFilter('status', $query['status'] ?? 'all', self::STATUS_LABELS);
        $type = $this->selectFilter('type', $query['type'] ?? 'all', self::TYPE_LABELS);
        $issueLabels = ['all' => 'Alle Probleme'] + ContentQualityAudit::ISSUE_LABELS;
        $issue = $this->selectFilter('issue', $query['issue'] ?? 'all', $issueLabels);
        $title = $query['q'] ?? '';
        if (!is_string($title) || !mb_check_encoding($title, 'UTF-8') || mb_strlen($title, 'UTF-8') > 120) {
            throw new \InvalidArgumentException('Die Titelsuche ist ungültig.');
        }

        $rawPage = $query['page'] ?? '1';
        if (!is_string($rawPage) || preg_match('/\A[1-9][0-9]{0,4}\z/D', $rawPage) !== 1) {
            throw new \InvalidArgumentException('Die Berichtsseite ist ungültig.');
        }
        $page = filter_var($rawPage, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => ContentQualityAudit::MAX_PAGES]]);
        if (!is_int($page)) {
            throw new \InvalidArgumentException('Die Berichtsseite ist ungültig.');
        }

        return ['status' => $status, 'type' => $type, 'issue' => $issue, 'q' => trim($title), 'page' => $page];
    }

    /** @param array<string, string> $allowed */
    private function selectFilter(string $key, mixed $raw, array $allowed): string
    {
        if (!is_string($raw) || !array_key_exists($raw, $allowed)) {
            throw new \InvalidArgumentException('Ein Berichtsfilter ist ungültig.');
        }

        return $raw;
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}

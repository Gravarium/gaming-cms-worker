<?php

declare(strict_types=1);

namespace App\Accessibility\Content;

use App\Entity\ContentEntry;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ContentAccessibilityReport
{
    private const PAGE_SIZE = 25;
    private const MAX_SCAN = 500;
    private const MAX_PAGE = 20;

    private const ISSUE_KEYS = [
        'missing_media_alt',
        'heading_order',
        'invalid_document',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContentAccessibilityAnalyzer $analyzer,
    ) {
    }

    /**
     * @param array<string, mixed> $rawFilters
     * @return array{
     *     items:list<array{id:int,title:string,type:string,status:string,updatedAt:\DateTimeImmutable,issueCounts:array{missing_media_alt:int,heading_order:int,invalid_document:int},issueTotal:int}>,
     *     filters:array{q:string,status:string,type:string,issue:string},
     *     summary:array{entries_with_issues:int,missing_media_alt:int,heading_order:int,invalid_document:int},
     *     scanTruncated:bool,
     *     scannedEntries:int,
     *     matchedEntries:int,
     *     page:int,
     *     pages:int,
     *     pageSize:int
     * }
     */
    public function build(array $rawFilters): array
    {
        $filters = $this->normalizeFilters($rawFilters);

        $builder = $this->entityManager->createQueryBuilder()
            ->select('entry')
            ->from(ContentEntry::class, 'entry')
            ->andWhere('entry.status != :trashed')
            ->setParameter('trashed', ContentEntry::STATUS_TRASHED)
            ->orderBy('entry.updatedAt', 'DESC')
            ->addOrderBy('entry.id', 'DESC')
            ->setMaxResults(self::MAX_SCAN + 1);

        if ($filters['q'] !== '') {
            $builder
                ->andWhere('LOWER(entry.title) LIKE :titleQuery')
                ->setParameter('titleQuery', '%'.mb_strtolower($filters['q']).'%');
        }

        if ($filters['status'] !== '') {
            $builder
                ->andWhere('entry.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if ($filters['type'] !== '') {
            $builder
                ->andWhere('entry.type = :type')
                ->setParameter('type', $filters['type']);
        }

        /** @var list<ContentEntry> $candidates */
        $candidates = $builder->getQuery()->getResult();
        $scanTruncated = count($candidates) > self::MAX_SCAN;
        if ($scanTruncated) {
            $candidates = array_slice($candidates, 0, self::MAX_SCAN);
        }

        $summary = [
            'entries_with_issues' => 0,
            'missing_media_alt' => 0,
            'heading_order' => 0,
            'invalid_document' => 0,
        ];
        $matchingEntries = [];

        foreach ($candidates as $entry) {
            $counts = $this->analyzer->analyze($entry->getEditableDocument());
            $issueTotal = array_sum($counts);
            if ($issueTotal > 0) {
                ++$summary['entries_with_issues'];
            }

            $summary['missing_media_alt'] += $counts['missing_media_alt'];
            $summary['heading_order'] += $counts['heading_order'];
            $summary['invalid_document'] += $counts['invalid_document'];

            if ($issueTotal === 0) {
                continue;
            }

            if ($filters['issue'] !== '') {
                $selectedCount = match ($filters['issue']) {
                    'missing_media_alt' => $counts['missing_media_alt'],
                    'heading_order' => $counts['heading_order'],
                    'invalid_document' => $counts['invalid_document'],
                    default => 0,
                };
                if ($selectedCount === 0) {
                    continue;
                }
            }

            $id = $entry->getId();
            if ($id === null) {
                continue;
            }

            $matchingEntries[] = [
                'id' => $id,
                'title' => $entry->getTitle(),
                'type' => $entry->getType(),
                'status' => $entry->getStatus(),
                'updatedAt' => $entry->getUpdatedAt(),
                'issueCounts' => $counts,
                'issueTotal' => $issueTotal,
            ];
        }

        $matchedEntries = count($matchingEntries);
        $pages = max(1, (int) ceil($matchedEntries / self::PAGE_SIZE));
        $page = min($filters['page'], $pages);
        $items = array_slice($matchingEntries, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        return [
            'items' => $items,
            'filters' => [
                'q' => $filters['q'],
                'status' => $filters['status'],
                'type' => $filters['type'],
                'issue' => $filters['issue'],
            ],
            'summary' => $summary,
            'scanTruncated' => $scanTruncated,
            'scannedEntries' => count($candidates),
            'matchedEntries' => $matchedEntries,
            'page' => $page,
            'pages' => $pages,
            'pageSize' => self::PAGE_SIZE,
        ];
    }

    /**
     * @param array<string, mixed> $rawFilters
     * @return array{q:string,status:string,type:string,issue:string,page:int}
     */
    private function normalizeFilters(array $rawFilters): array
    {
        $allowedKeys = ['q', 'status', 'type', 'issue', 'page'];
        foreach (array_keys($rawFilters) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                throw new \InvalidArgumentException('Unbekannter Filter.');
            }
        }

        $readString = static function (array $filters, string $key): string {
            if (!array_key_exists($key, $filters)) {
                return '';
            }

            if (!is_string($filters[$key])) {
                throw new \InvalidArgumentException('Ungültiger Filterwert.');
            }

            return $filters[$key];
        };

        $query = $readString($rawFilters, 'q');
        if (!mb_check_encoding($query, 'UTF-8') || mb_strlen($query, 'UTF-8') > 80) {
            throw new \InvalidArgumentException('Die Titelsuche ist zu lang oder ungültig.');
        }
        $query = trim($query);

        $status = $readString($rawFilters, 'status');
        $validStatuses = [
            ContentEntry::STATUS_DRAFT,
            ContentEntry::STATUS_REVIEW,
            ContentEntry::STATUS_SCHEDULED,
            ContentEntry::STATUS_PUBLISHED,
            ContentEntry::STATUS_ARCHIVED,
        ];
        if ($status !== '' && !in_array($status, $validStatuses, true)) {
            throw new \InvalidArgumentException('Ungültiger Statusfilter.');
        }

        $type = $readString($rawFilters, 'type');
        if ($type !== '' && !in_array($type, [ContentEntry::TYPE_PAGE, ContentEntry::TYPE_NEWS], true)) {
            throw new \InvalidArgumentException('Ungültiger Typfilter.');
        }

        $issue = $readString($rawFilters, 'issue');
        if ($issue !== '' && !in_array($issue, self::ISSUE_KEYS, true)) {
            throw new \InvalidArgumentException('Ungültiger Problemfilter.');
        }

        $rawPage = array_key_exists('page', $rawFilters) ? $readString($rawFilters, 'page') : '1';
        if (preg_match('/^[1-9][0-9]*$/D', $rawPage) !== 1 || (int) $rawPage > self::MAX_PAGE) {
            throw new \InvalidArgumentException('Ungültige Berichtsseite.');
        }

        return [
            'q' => $query,
            'status' => $status,
            'type' => $type,
            'issue' => $issue,
            'page' => (int) $rawPage,
        ];
    }
}

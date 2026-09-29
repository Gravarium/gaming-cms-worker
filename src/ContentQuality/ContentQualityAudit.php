<?php

declare(strict_types=1);

namespace App\ContentQuality;

use App\Entity\ContentEntry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class ContentQualityAudit
{
    public const PAGE_SIZE = 50;
    public const MAX_PAGES = 10000;

    /** @var array<string, string> */
    public const ISSUE_LABELS = [
        'missing_excerpt' => 'Auszug fehlt',
        'missing_seo_title' => 'SEO-Titel fehlt',
        'missing_seo_description' => 'SEO-Beschreibung fehlt',
        'missing_canonical_url' => 'Kanonische URL fehlt',
        'published_noindex' => 'Veröffentlichter Inhalt ist auf noindex gesetzt',
    ];

    /** @var list<string> */
    public const STATUSES = [
        ContentEntry::STATUS_DRAFT,
        ContentEntry::STATUS_REVIEW,
        ContentEntry::STATUS_SCHEDULED,
        ContentEntry::STATUS_PUBLISHED,
        ContentEntry::STATUS_ARCHIVED,
    ];

    /** @var list<string> */
    public const TYPES = [ContentEntry::TYPE_NEWS, ContentEntry::TYPE_PAGE];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{
     *     rows: list<array{entry: ContentEntry, issues: list<string>}>,
     *     summary: array<string, int>,
     *     contentTotal: int,
     *     total: int,
     *     page: int,
     *     pages: int,
     *     pageSize: int
     * }
     */
    public function report(string $status, string $type, string $issue, string $titleQuery, int $page): array
    {
        if ($page < 1 || $page > self::MAX_PAGES) {
            throw new \InvalidArgumentException('Die Berichtsseite ist ungültig.');
        }
        if ($status !== 'all' && !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Der Statusfilter ist ungültig.');
        }
        if ($type !== 'all' && !in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Der Typfilter ist ungültig.');
        }
        if ($issue !== 'all' && !isset(self::ISSUE_LABELS[$issue])) {
            throw new \InvalidArgumentException('Der Problemfilter ist ungültig.');
        }
        if (mb_strlen($titleQuery, 'UTF-8') > 120) {
            throw new \InvalidArgumentException('Die Titelsuche ist zu lang.');
        }

        $summary = ['content_total' => $this->countAllContent()];
        foreach (array_keys(self::ISSUE_LABELS) as $issueCode) {
            $summary[$issueCode] = $this->countIssue($issueCode);
        }

        $countQuery = $this->filteredQuery($status, $type, $issue, $titleQuery);
        $total = (int) $countQuery->select('COUNT(entry.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $entriesQuery = $this->filteredQuery($status, $type, $issue, $titleQuery);
        $entriesQuery
            ->orderBy('entry.updatedAt', 'DESC')
            ->addOrderBy('entry.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE);

        /** @var list<ContentEntry> $entries */
        $entries = $entriesQuery->getQuery()->getResult();
        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = ['entry' => $entry, 'issues' => $this->issuesFor($entry)];
        }

        return [
            'rows' => $rows,
            'summary' => $summary,
            'contentTotal' => $summary['content_total'],
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'pageSize' => self::PAGE_SIZE,
        ];
    }

    private function countAllContent(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(entry.id)')
            ->from(ContentEntry::class, 'entry')
            ->andWhere('entry.status <> :trashed')
            ->setParameter('trashed', ContentEntry::STATUS_TRASHED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countIssue(string $issue): int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('COUNT(entry.id)')
            ->from(ContentEntry::class, 'entry')
            ->andWhere('entry.status <> :trashed')
            ->andWhere($this->issuePredicate($issue))
            ->setParameter('trashed', ContentEntry::STATUS_TRASHED);
        if ($issue === 'published_noindex') {
            $query->setParameter('published', ContentEntry::STATUS_PUBLISHED);
        }

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    private function filteredQuery(string $status, string $type, string $issue, string $titleQuery): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('entry')
            ->from(ContentEntry::class, 'entry')
            ->andWhere('entry.status <> :trashed')
            ->setParameter('trashed', ContentEntry::STATUS_TRASHED);

        if ($status !== 'all') {
            $query->andWhere('entry.status = :status')->setParameter('status', $status);
        }
        if ($type !== 'all') {
            $query->andWhere('entry.type = :type')->setParameter('type', $type);
        }
        if ($titleQuery !== '') {
            $query->andWhere('LOWER(entry.title) LIKE :titleQuery')
                ->setParameter('titleQuery', '%'.mb_strtolower($titleQuery, 'UTF-8').'%');
        }

        if ($issue === 'all') {
            $predicates = [];
            foreach (array_keys(self::ISSUE_LABELS) as $issueCode) {
                $predicates[] = '('.$this->issuePredicate($issueCode).')';
            }
            $query->andWhere('('.implode(' OR ', $predicates).')')
                ->setParameter('published', ContentEntry::STATUS_PUBLISHED);
        } else {
            $query->andWhere($this->issuePredicate($issue));
            if ($issue === 'published_noindex') {
                $query->setParameter('published', ContentEntry::STATUS_PUBLISHED);
            }
        }

        return $query;
    }

    private function issuePredicate(string $issue): string
    {
        return match ($issue) {
            'missing_excerpt' => '(entry.excerpt IS NULL OR entry.excerpt = \'\')',
            'missing_seo_title' => '(entry.seoTitle IS NULL OR entry.seoTitle = \'\')',
            'missing_seo_description' => '(entry.seoDescription IS NULL OR entry.seoDescription = \'\')',
            'missing_canonical_url' => '(entry.canonicalUrl IS NULL OR entry.canonicalUrl = \'\')',
            'published_noindex' => '(entry.status = :published AND entry.noIndex = true)',
            default => throw new \InvalidArgumentException('Unbekannter Qualitätsindikator.'),
        };
    }

    /** @return list<string> */
    private function issuesFor(ContentEntry $entry): array
    {
        $issues = [];
        if (trim($entry->getExcerpt() ?? '') === '') {
            $issues[] = 'missing_excerpt';
        }
        if (trim($entry->getSeoTitle() ?? '') === '') {
            $issues[] = 'missing_seo_title';
        }
        if (trim($entry->getSeoDescription() ?? '') === '') {
            $issues[] = 'missing_seo_description';
        }
        if (trim($entry->getCanonicalUrl() ?? '') === '') {
            $issues[] = 'missing_canonical_url';
        }
        if ($entry->getStatus() === ContentEntry::STATUS_PUBLISHED && $entry->isNoIndex()) {
            $issues[] = 'published_noindex';
        }

        return $issues;
    }
}

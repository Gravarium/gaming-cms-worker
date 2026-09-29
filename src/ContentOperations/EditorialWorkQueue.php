<?php

declare(strict_types=1);

namespace App\ContentOperations;

use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use Doctrine\ORM\QueryBuilder;

final readonly class EditorialWorkQueue
{
    public const KIND_REVIEW = 'review';
    public const KIND_STALE_DRAFT = 'stale_draft';
    public const KIND_PUBLICATION_DUE = 'publication_due';
    public const KIND_PUBLICATION_UPCOMING = 'publication_upcoming';
    public const KIND_UNPUBLICATION_DUE = 'unpublication_due';
    public const KIND_UNPUBLICATION_UPCOMING = 'unpublication_upcoming';

    public const PAGE_SIZE = 25;
    public const MAX_PAGE = 1000;
    public const MAX_TITLE_QUERY_LENGTH = 120;

    /** @var array<string, string> */
    public const WORK_TYPE_LABELS = [
        self::KIND_REVIEW => 'Freigabe ausstehend',
        self::KIND_STALE_DRAFT => 'Veraltete Entwürfe',
        self::KIND_PUBLICATION_DUE => 'Veröffentlichung überfällig',
        self::KIND_PUBLICATION_UPCOMING => 'Veröffentlichung demnächst',
        self::KIND_UNPUBLICATION_DUE => 'Rücknahme überfällig',
        self::KIND_UNPUBLICATION_UPCOMING => 'Rücknahme demnächst',
    ];

    /** @var list<string> */
    private const FILTER_STATUSES = [
        ContentEntry::STATUS_DRAFT,
        ContentEntry::STATUS_REVIEW,
        ContentEntry::STATUS_SCHEDULED,
        ContentEntry::STATUS_PUBLISHED,
    ];

    public function __construct(private ContentEntryRepository $entries)
    {
    }

    /**
     * @return array{
     *     items: list<array{entry: ContentEntry, kind: string, kind_label: string, action_at: \DateTimeImmutable}>,
     *     counts: array<string, int>,
     *     total: int,
     *     filtered_total: int,
     *     has_next: bool,
     *     page: int,
     *     page_size: int
     * }
     */
    public function paginate(
        ?string $status,
        ?string $type,
        ?string $kind,
        string $titleQuery,
        int $page,
        \DateTimeImmutable $now,
    ): array {
        if ($status !== null && !in_array($status, self::FILTER_STATUSES, true)) {
            throw new \InvalidArgumentException('Unsupported content status filter.');
        }
        if ($type !== null && !in_array($type, [ContentEntry::TYPE_NEWS, ContentEntry::TYPE_PAGE], true)) {
            throw new \InvalidArgumentException('Unsupported content type filter.');
        }
        if ($kind !== null && !isset(self::WORK_TYPE_LABELS[$kind])) {
            throw new \InvalidArgumentException('Unsupported editorial work type filter.');
        }
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new \InvalidArgumentException('Editorial work queue page is outside the supported range.');
        }

        $titleQuery = trim($titleQuery);
        if (mb_strlen($titleQuery) > self::MAX_TITLE_QUERY_LENGTH) {
            throw new \InvalidArgumentException('Editorial work queue title query is too long.');
        }

        $staleBefore = $now->sub(new \DateInterval('P14D'));
        $horizon = $now->add(new \DateInterval('P7D'));
        $counts = [];

        foreach (array_keys(self::WORK_TYPE_LABELS) as $workType) {
            $countBuilder = $this->baseBuilder($status, $type, $titleQuery);
            $this->applyWorkType($countBuilder, $workType, $now, $staleBefore, $horizon);
            $countBuilder->select('COUNT(entry.id)');
            $counts[$workType] = (int) $countBuilder->getQuery()->getSingleScalarResult();
        }

        $total = (int) array_sum($counts);
        $filteredTotal = $kind === null ? $total : $counts[$kind];

        $builder = $this->baseBuilder($status, $type, $titleQuery);
        $workTypes = $kind === null ? array_keys(self::WORK_TYPE_LABELS) : [$kind];
        $conditions = $builder->expr()->orX();

        foreach ($workTypes as $workType) {
            $specification = $this->workTypeSpecification($workType, $now, $staleBefore, $horizon);
            $conditions->add($specification['condition']);
            foreach ($specification['parameters'] as $name => $value) {
                $builder->setParameter($name, $value);
            }
        }

        $builder->andWhere($conditions)
            ->addSelect($this->priorityExpression().' AS HIDDEN queuePriority')
            ->setParameter('queueNow', $now)
            ->setParameter('queueHorizon', $horizon)
            ->orderBy('queuePriority', 'ASC')
            ->addOrderBy('COALESCE(entry.scheduledUnpublishAt, entry.scheduledAt, entry.updatedAt)', 'ASC')
            ->addOrderBy('entry.updatedAt', 'ASC')
            ->addOrderBy('entry.id', 'ASC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE + 1);

        /** @var list<ContentEntry> $entries */
        $entries = $builder->getQuery()->getResult();
        $hasNext = count($entries) > self::PAGE_SIZE;
        if ($hasNext) {
            array_pop($entries);
        }

        /** @var list<array{entry: ContentEntry, kind: string, kind_label: string, action_at: \DateTimeImmutable}> $items */
        $items = [];
        foreach ($entries as $entry) {
            $workType = $this->resolveWorkType($entry, $now, $staleBefore);
            $items[] = [
                'entry' => $entry,
                'kind' => $workType,
                'kind_label' => self::WORK_TYPE_LABELS[$workType],
                'action_at' => $this->actionDate($entry, $workType),
            ];
        }

        return [
            'items' => $items,
            'counts' => $counts,
            'total' => $total,
            'filtered_total' => $filteredTotal,
            'has_next' => $hasNext,
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
        ];
    }

    private function baseBuilder(?string $status, ?string $type, string $titleQuery): QueryBuilder
    {
        $builder = $this->entries->createQueryBuilder('entry');

        if ($status !== null) {
            $builder->andWhere('entry.status = :queueFilterStatus')
                ->setParameter('queueFilterStatus', $status);
        }
        if ($type !== null) {
            $builder->andWhere('entry.type = :queueFilterType')
                ->setParameter('queueFilterType', $type);
        }
        if ($titleQuery !== '') {
            $builder->andWhere('LOWER(entry.title) LIKE :queueTitleQuery')
                ->setParameter('queueTitleQuery', '%'.mb_strtolower($titleQuery).'%');
        }

        return $builder;
    }

    private function applyWorkType(
        QueryBuilder $builder,
        string $workType,
        \DateTimeImmutable $now,
        \DateTimeImmutable $staleBefore,
        \DateTimeImmutable $horizon,
    ): void {
        $specification = $this->workTypeSpecification($workType, $now, $staleBefore, $horizon);
        $builder->andWhere($specification['condition']);
        foreach ($specification['parameters'] as $name => $value) {
            $builder->setParameter($name, $value);
        }
    }

    /**
     * @return array{condition: string, parameters: array<string, string|\DateTimeImmutable>}
     */
    private function workTypeSpecification(
        string $workType,
        \DateTimeImmutable $now,
        \DateTimeImmutable $staleBefore,
        \DateTimeImmutable $horizon,
    ): array {
        return match ($workType) {
            self::KIND_REVIEW => [
                'condition' => 'entry.status = :queueReviewStatus',
                'parameters' => ['queueReviewStatus' => ContentEntry::STATUS_REVIEW],
            ],
            self::KIND_STALE_DRAFT => [
                'condition' => 'entry.status = :queueDraftStatus AND entry.updatedAt < :queueStaleBefore',
                'parameters' => [
                    'queueDraftStatus' => ContentEntry::STATUS_DRAFT,
                    'queueStaleBefore' => $staleBefore,
                ],
            ],
            self::KIND_PUBLICATION_DUE => [
                'condition' => 'entry.status = :queueDueScheduledStatus AND entry.scheduledAt IS NOT NULL AND entry.scheduledAt <= :queueNow',
                'parameters' => [
                    'queueDueScheduledStatus' => ContentEntry::STATUS_SCHEDULED,
                    'queueNow' => $now,
                ],
            ],
            self::KIND_PUBLICATION_UPCOMING => [
                'condition' => 'entry.status = :queueUpcomingScheduledStatus AND entry.scheduledAt > :queueNow AND entry.scheduledAt <= :queueHorizon',
                'parameters' => [
                    'queueUpcomingScheduledStatus' => ContentEntry::STATUS_SCHEDULED,
                    'queueNow' => $now,
                    'queueHorizon' => $horizon,
                ],
            ],
            self::KIND_UNPUBLICATION_DUE => [
                'condition' => 'entry.status = :queueDuePublishedStatus AND entry.scheduledUnpublishAt IS NOT NULL AND entry.scheduledUnpublishAt <= :queueNow',
                'parameters' => [
                    'queueDuePublishedStatus' => ContentEntry::STATUS_PUBLISHED,
                    'queueNow' => $now,
                ],
            ],
            self::KIND_UNPUBLICATION_UPCOMING => [
                'condition' => 'entry.status = :queueUpcomingPublishedStatus AND entry.scheduledUnpublishAt > :queueNow AND entry.scheduledUnpublishAt <= :queueHorizon',
                'parameters' => [
                    'queueUpcomingPublishedStatus' => ContentEntry::STATUS_PUBLISHED,
                    'queueNow' => $now,
                    'queueHorizon' => $horizon,
                ],
            ],
            default => throw new \InvalidArgumentException('Unsupported editorial work type.'),
        };
    }

    private function priorityExpression(): string
    {
        return sprintf(
            "CASE WHEN entry.status = '%s' AND entry.scheduledUnpublishAt <= :queueNow THEN 0 "
            ."WHEN entry.status = '%s' AND entry.scheduledAt <= :queueNow THEN 1 "
            ."WHEN entry.status = '%s' THEN 2 "
            ."WHEN entry.status = '%s' AND entry.scheduledUnpublishAt <= :queueHorizon THEN 3 "
            ."WHEN entry.status = '%s' AND entry.scheduledAt <= :queueHorizon THEN 4 "
            .'ELSE 5 END',
            ContentEntry::STATUS_PUBLISHED,
            ContentEntry::STATUS_SCHEDULED,
            ContentEntry::STATUS_REVIEW,
            ContentEntry::STATUS_PUBLISHED,
            ContentEntry::STATUS_SCHEDULED,
        );
    }

    private function resolveWorkType(ContentEntry $entry, \DateTimeImmutable $now, \DateTimeImmutable $staleBefore): string
    {
        if ($entry->getStatus() === ContentEntry::STATUS_REVIEW) {
            return self::KIND_REVIEW;
        }
        if ($entry->getStatus() === ContentEntry::STATUS_DRAFT && $entry->getUpdatedAt() < $staleBefore) {
            return self::KIND_STALE_DRAFT;
        }
        if ($entry->getStatus() === ContentEntry::STATUS_SCHEDULED && $entry->getScheduledAt() !== null) {
            return $entry->getScheduledAt() <= $now ? self::KIND_PUBLICATION_DUE : self::KIND_PUBLICATION_UPCOMING;
        }
        if ($entry->getStatus() === ContentEntry::STATUS_PUBLISHED && $entry->getScheduledUnpublishAt() !== null) {
            return $entry->getScheduledUnpublishAt() <= $now ? self::KIND_UNPUBLICATION_DUE : self::KIND_UNPUBLICATION_UPCOMING;
        }

        throw new \LogicException('The editorial work queue returned an entry without a work type.');
    }

    private function actionDate(ContentEntry $entry, string $workType): \DateTimeImmutable
    {
        return match ($workType) {
            self::KIND_PUBLICATION_DUE, self::KIND_PUBLICATION_UPCOMING => $entry->getScheduledAt() ?? $entry->getUpdatedAt(),
            self::KIND_UNPUBLICATION_DUE, self::KIND_UNPUBLICATION_UPCOMING => $entry->getScheduledUnpublishAt() ?? $entry->getUpdatedAt(),
            default => $entry->getUpdatedAt(),
        };
    }
}

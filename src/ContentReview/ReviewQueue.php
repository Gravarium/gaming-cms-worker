<?php

declare(strict_types=1);

namespace App\ContentReview;

use App\Entity\ContentEntry;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ReviewQueue
{
    public const PAGE_SIZE = 25;
    public const MAX_PAGE = 10000;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{entries: list<ContentEntry>, page: int, pageCount: int, total: int}
     */
    public function read(mixed $rawPage): array
    {
        $page = $this->parsePage($rawPage);
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(entry.id)')
            ->from(ContentEntry::class, 'entry')
            ->andWhere('entry.status = :status')
            ->setParameter('status', ContentEntry::STATUS_REVIEW)
            ->getQuery()
            ->getSingleScalarResult();
        $total = (int) $count;
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $pageCount) {
            throw new \OutOfRangeException('Review page is outside the available range.');
        }

        /** @var list<ContentEntry> $entries */
        $entries = $this->entityManager->createQueryBuilder()
            ->select('entry')
            ->from(ContentEntry::class, 'entry')
            ->andWhere('entry.status = :status')
            ->setParameter('status', ContentEntry::STATUS_REVIEW)
            ->orderBy('entry.updatedAt', 'DESC')
            ->addOrderBy('entry.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        return ['entries' => $entries, 'page' => $page, 'pageCount' => $pageCount, 'total' => $total];
    }

    private function parsePage(mixed $value): int
    {
        if ($value === null) {
            return 1;
        }

        if (is_int($value)) {
            $page = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]{0,4}$/D', $value) === 1) {
            $page = (int) $value;
        } else {
            throw new \InvalidArgumentException('Review page must be a positive decimal integer.');
        }

        if ($page < 1) {
            throw new \InvalidArgumentException('Review page must be a positive decimal integer.');
        }
        if ($page > self::MAX_PAGE) {
            throw new \OutOfRangeException('Review page exceeds the supported limit.');
        }

        return $page;
    }
}

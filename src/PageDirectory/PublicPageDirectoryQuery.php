<?php

declare(strict_types=1);

namespace App\\PageDirectory;

use App\\Entity\\ContentEntry;
use Doctrine\\ORM\\EntityManagerInterface;
use Doctrine\\ORM\\QueryBuilder;

final readonly class PublicPageDirectoryQuery
{
    public const PAGE_SIZE = 20;
    public const MAX_PAGES = 10_000;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function countPublicPages(\\DateTimeImmutable $now): int
    {
        return (int) $this->publicPages($now)
            ->select('COUNT(entry.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<ContentEntry> */
    public function findPage(int $page, \\DateTimeImmutable $now): array
    {
        if ($page < 1 || $page > self::MAX_PAGES) {
            throw new \\InvalidArgumentException('Page is outside the public page directory range.');
        }

        /** @var list<ContentEntry> $entries */
        $entries = $this->publicPages($now)
            ->orderBy('entry.publishedAt', 'DESC')
            ->addOrderBy('entry.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        return $entries;
    }

    private function publicPages(\\DateTimeImmutable $now): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('entry')
            ->from(ContentEntry::class, 'entry')
            ->andWhere('entry.type = :type')
            ->andWhere('entry.status = :status')
            ->andWhere('entry.unlisted = false')
            ->andWhere('entry.publishedAt IS NOT NULL')
            ->andWhere('entry.publishedAt <= :now')
            ->setParameter('type', ContentEntry::TYPE_PAGE)
            ->setParameter('status', ContentEntry::STATUS_PUBLISHED)
            ->setParameter('now', $now);
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentRedirect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ContentRedirect> */
final class ContentRedirectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentRedirect::class);
    }

    public function findTarget(string $type, string $slug): ?ContentRedirect
    {
        return $this->findOneBy(['type' => $type, 'sourceSlug' => $slug]);
    }

    public function countAdminResults(string $search, string $type): int
    {
        $query = $this->createAdminQuery($search, $type)
            ->select('COUNT(redirect.id)');

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<ContentRedirect>
     */
    public function findAdminPage(string $search, string $type, int $limit, int $offset): array
    {
        /** @var list<ContentRedirect> $redirects */
        $redirects = $this->createAdminQuery($search, $type)
            ->addSelect('target')
            ->orderBy('redirect.createdAt', 'DESC')
            ->addOrderBy('redirect.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $redirects;
    }

    private function createAdminQuery(string $search, string $type): QueryBuilder
    {
        $query = $this->createQueryBuilder('redirect')
            ->innerJoin('redirect.entry', 'target');

        if ($type !== '') {
            $query->andWhere('redirect.type = :type')
                ->setParameter('type', $type);
        }

        if ($search !== '') {
            $query->andWhere(
                $query->expr()->orX(
                    'LOWER(redirect.sourceSlug) LIKE :search',
                    'LOWER(target.title) LIKE :search',
                ),
            )->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        return $query;
    }
}

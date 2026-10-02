<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NewsBlockSnippet;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<NewsBlockSnippet> */
final class NewsBlockSnippetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, NewsBlockSnippet::class); }

    /** @return list<NewsBlockSnippet> */
    public function forOwner(User $owner, string $query = ''): array
    {
        $builder = $this->createQueryBuilder('snippet')->andWhere('snippet.owner = :owner')->setParameter('owner', $owner)
            ->orderBy('snippet.updatedAt', 'DESC')->addOrderBy('snippet.id', 'DESC')->setMaxResults(30);
        if ($query !== '') {
            $builder->andWhere('LOCATE(:query, LOWER(snippet.label)) > 0')->setParameter('query', mb_strtolower($query));
        }
        return $builder->getQuery()->getResult();
    }

    public function countForOwner(User $owner): int
    {
        return $this->count(['owner' => $owner]);
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Category> */
final class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $builder = $this->createQueryBuilder('category')
            ->select('COUNT(category.id)')
            ->andWhere('category.slug = :slug')
            ->setParameter('slug', $slug);

        if ($exceptId !== null) {
            $builder->andWhere('category.id != :id')->setParameter('id', $exceptId);
        }

        return (int) $builder->getQuery()->getSingleScalarResult() > 0;
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VideoCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<VideoCategory> */
final class VideoCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, VideoCategory::class); }

    /** @return list<VideoCategory> */
    public function findEnabled(): array
    {
        return $this->findBy(['enabled' => true], ['name' => 'ASC']);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $builder = $this->createQueryBuilder('category')->select('COUNT(category.id)')
            ->andWhere('category.slug = :slug')->setParameter('slug', $slug);
        if ($exceptId !== null) { $builder->andWhere('category.id != :id')->setParameter('id', $exceptId); }
        return (int) $builder->getQuery()->getSingleScalarResult() > 0;
    }
}

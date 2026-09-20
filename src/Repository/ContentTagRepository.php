<?php

declare(strict_types=1);
namespace App\Repository;
use App\Entity\ContentTag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
/** @extends ServiceEntityRepository<ContentTag> */
final class ContentTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, ContentTag::class); }
    public function findOneBySlug(string $slug): ?ContentTag { return $this->findOneBy(['slug' => $slug]); }
    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $builder = $this->createQueryBuilder('tag')->select('COUNT(tag.id)')->andWhere('tag.slug = :slug')->setParameter('slug', $slug);
        if ($exceptId !== null) { $builder->andWhere('tag.id != :id')->setParameter('id', $exceptId); }
        return (int) $builder->getQuery()->getSingleScalarResult() > 0;
    }
}

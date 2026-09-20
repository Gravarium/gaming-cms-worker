<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guild;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Guild> */
final class GuildRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Guild::class); }

    /** @return list<Guild> */
    public function findPublicGuilds(): array
    {
        return $this->createQueryBuilder('guild')
            ->addSelect('game')->join('guild.game', 'game')
            ->andWhere('guild.enabled = true')->andWhere('game.enabled = true')
            ->orderBy('game.name', 'ASC')->addOrderBy('guild.name', 'ASC')
            ->getQuery()->getResult();
    }

    public function findPublicBySlug(string $slug): ?Guild
    {
        return $this->createQueryBuilder('guild')
            ->addSelect('game')->join('guild.game', 'game')
            ->andWhere('guild.slug = :slug')->andWhere('guild.enabled = true')->andWhere('game.enabled = true')
            ->setParameter('slug', $slug)->getQuery()->getOneOrNullResult();
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $builder = $this->createQueryBuilder('guild')->select('COUNT(guild.id)')
            ->andWhere('guild.slug = :slug')->setParameter('slug', $slug);
        if ($exceptId !== null) {
            $builder->andWhere('guild.id != :id')->setParameter('id', $exceptId);
        }
        return (int) $builder->getQuery()->getSingleScalarResult() > 0;
    }
}

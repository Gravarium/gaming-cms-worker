<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Game;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Game> */
final class GameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Game::class); }

    /** @return list<Game> */
    public function findEnabled(): array
    {
        return $this->findBy(['enabled' => true], ['name' => 'ASC']);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $builder = $this->createQueryBuilder('game')->select('COUNT(game.id)')
            ->andWhere('game.slug = :slug')->setParameter('slug', $slug);
        if ($exceptId !== null) {
            $builder->andWhere('game.id != :id')->setParameter('id', $exceptId);
        }
        return (int) $builder->getQuery()->getSingleScalarResult() > 0;
    }
}

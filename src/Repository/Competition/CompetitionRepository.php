<?php

declare(strict_types=1);

namespace App\Repository\Competition;

use App\Entity\Competition\Competition;
use App\Entity\Game;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Competition> */
final class CompetitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Competition::class); }

    /** @return list<Game> */
    public function publicCompetitionGames(): array
    {
        $builder = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT game')
            ->from(Game::class, 'game')
            ->from(Competition::class, 'competition')
            ->where('competition.game = game')
            ->andWhere('competition.visibility = :visibility')
            ->andWhere('competition.status <> :draft')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('visibility', Competition::VISIBILITY_PUBLIC)
            ->setParameter('draft', Competition::STATUS_DRAFT)
            ->setParameter('enabled', true)
            ->orderBy('game.name', 'ASC');

        /** @var list<Game> $games */
        $games = $builder->getQuery()->getResult();

        return $games;
    }

    /** @return list<Competition> */
    public function publicCompetitions(?string $status = null, ?string $gameSlug = null, ?string $mode = null): array
    {
        $builder = $this->createQueryBuilder('competition')
            ->join('competition.game', 'game')
            ->andWhere('competition.visibility = :visibility')
            ->andWhere('competition.status <> :draft')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('visibility', Competition::VISIBILITY_PUBLIC)
            ->setParameter('draft', Competition::STATUS_DRAFT)
            ->setParameter('enabled', true);

        if ($status !== null) {
            $builder->andWhere('competition.status = :status')->setParameter('status', $status);
        }
        if ($gameSlug !== null) {
            $builder->andWhere('game.slug = :gameSlug')->setParameter('gameSlug', $gameSlug);
        }
        if ($mode !== null) {
            $builder->andWhere('competition.mode = :mode')->setParameter('mode', $mode);
        }

        return $builder->orderBy('competition.startsAt', 'ASC')->getQuery()->getResult();
    }

    /** @return list<Competition> */
    public function recentForAdmin(): array
    {
        return $this->findBy([], ['startsAt' => 'DESC']);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $builder = $this->createQueryBuilder('competition')->select('COUNT(competition.id)')
            ->andWhere('competition.slug = :slug')->setParameter('slug', $slug);
        if ($exceptId !== null) {
            $builder->andWhere('competition.id != :id')->setParameter('id', $exceptId);
        }
        return (int) $builder->getQuery()->getSingleScalarResult() > 0;
    }
}

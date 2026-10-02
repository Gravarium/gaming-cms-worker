<?php

declare(strict_types=1);

namespace App\CompetitionSeason;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionSeason;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class PublicSeasonBrowser
{
    public const PAGE_SIZE = 20;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return array{rows: list<array{season: CompetitionSeason, count: int}>, total: int} */
    public function seasons(int $page): array
    {
        $total = (int) $this->visibleCompetitions()
            ->select('COUNT(DISTINCT season.id)')
            ->getQuery()->getSingleScalarResult();

        $results = $this->visibleCompetitions()
            ->select('season.id AS seasonId', 'COUNT(competition.id) AS competitionCount')
            ->groupBy('season.id')
            ->orderBy('season.startsAt', 'DESC')
            ->addOrderBy('season.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()->getResult();

        $rows = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $season = $this->entityManager->find(CompetitionSeason::class, (int) $result['seasonId']);
            if ($season instanceof CompetitionSeason) {
                $rows[] = ['season' => $season, 'count' => (int) $result['competitionCount']];
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array{competitions: list<Competition>, total: int} */
    public function competitions(CompetitionSeason $season, int $page): array
    {
        $builder = $this->visibleCompetitions()
            ->andWhere('season = :season')->setParameter('season', $season);
        $total = (int) (clone $builder)->select('COUNT(competition.id)')
            ->getQuery()->getSingleScalarResult();
        /** @var list<Competition> $competitions */
        $competitions = $builder->select('competition')
            ->orderBy('competition.startsAt', 'ASC')
            ->addOrderBy('competition.id', 'ASC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()->getResult();

        return ['competitions' => $competitions, 'total' => $total];
    }

    private function visibleCompetitions(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from(Competition::class, 'competition')
            ->join('competition.season', 'season')
            ->join('competition.game', 'game')
            ->join('season.game', 'seasonGame')
            ->andWhere('competition.visibility = :public')
            ->andWhere('competition.status <> :draft')
            ->andWhere('game.enabled = :enabled')
            ->andWhere('seasonGame.enabled = :enabled')
            ->setParameter('public', Competition::VISIBILITY_PUBLIC)
            ->setParameter('draft', Competition::STATUS_DRAFT)
            ->setParameter('enabled', true);
    }
}

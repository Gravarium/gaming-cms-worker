<?php

declare(strict_types=1);

namespace App\CompetitionResultsArchive;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ConfirmedResultsPage
{
    public const PAGE_SIZE = 50;
    public const MAX_PAGE = 100;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return array{matches: list<CompetitionMatch>, hasMore: bool} */
    public function forCompetition(Competition $competition, int $page): array
    {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new \InvalidArgumentException('Invalid archive page.');
        }

        /** @var list<CompetitionMatch> $matches */
        $matches = $this->entityManager->createQueryBuilder()
            ->select('m', 'a', 'b')
            ->from(CompetitionMatch::class, 'm')
            ->join('m.participantA', 'a')
            ->join('m.participantB', 'b')
            ->where('m.competition = :competition')
            ->andWhere('m.status = :status')
            ->andWhere('m.scoreA IS NOT NULL')
            ->andWhere('m.scoreB IS NOT NULL')
            ->setParameter('competition', $competition)
            ->setParameter('status', CompetitionMatch::STATUS_CONFIRMED)
            ->orderBy('m.roundNumber', 'DESC')
            ->addOrderBy('m.bracket', 'ASC')
            ->addOrderBy('m.sequence', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE + 1)
            ->getQuery()
            ->getResult();

        $hasMore = count($matches) > self::PAGE_SIZE;

        return ['matches' => array_slice($matches, 0, self::PAGE_SIZE), 'hasMore' => $hasMore];
    }
}

<?php

declare(strict_types=1);

namespace App\CompetitionMatchCalendar;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use Doctrine\ORM\EntityManagerInterface;

final class PublicMatchSchedule
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return list<CompetitionMatch> */
    public function upcoming(?string $gameSlug): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('m', 'competition', 'game')
            ->from(CompetitionMatch::class, 'm')
            ->join('m.competition', 'competition')
            ->join('competition.game', 'game')
            ->andWhere('competition.visibility = :public')
            ->andWhere('competition.status <> :draft')
            ->andWhere('game.enabled = :enabled')
            ->andWhere('m.scheduledAt >= :now')
            ->andWhere('m.scheduledAt < :until')
            ->andWhere('m.status <> :cancelled')
            ->setParameter('public', Competition::VISIBILITY_PUBLIC)
            ->setParameter('draft', Competition::STATUS_DRAFT)
            ->setParameter('enabled', true)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setParameter('until', new \DateTimeImmutable('+90 days', new \DateTimeZone('UTC')))
            ->setParameter('cancelled', CompetitionMatch::STATUS_CANCELLED)
            ->orderBy('m.scheduledAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->setMaxResults(100);

        if ($gameSlug !== null) {
            $query->andWhere('game.slug = :gameSlug')->setParameter('gameSlug', $gameSlug);
        }

        /** @var list<CompetitionMatch> $matches */
        $matches = $query->getQuery()->getResult();

        return $matches;
    }
}

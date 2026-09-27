<?php

declare(strict_types=1);

namespace App\Widget;

use App\Entity\GuildEvent;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PublicGuildEventsQuery
{
    public const MAX_RESULTS = 12;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<GuildEvent> */
    public function upcoming(\DateTimeImmutable $now, int $limit = self::MAX_RESULTS): array
    {
        $safeLimit = max(1, min(self::MAX_RESULTS, $limit));
        $cutoff = $now->modify('-2 hours');

        /** @var list<GuildEvent> $events */
        $events = $this->entityManager->getRepository(GuildEvent::class)
            ->createQueryBuilder('event')
            ->addSelect('guild', 'game')
            ->innerJoin('event.guild', 'guild')
            ->innerJoin('guild.game', 'game')
            ->andWhere('event.status = :planned')
            ->andWhere('event.startsAt >= :cutoff')
            ->andWhere('event.team IS NULL')
            ->andWhere('guild.enabled = true')
            ->andWhere('game.enabled = true')
            ->setParameter('planned', GuildEvent::STATUS_PLANNED)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('event.startsAt', 'ASC')
            ->addOrderBy('event.id', 'ASC')
            ->setMaxResults($safeLimit)
            ->getQuery()
            ->getResult();

        return $events;
    }
}

<?php

declare(strict_types=1);

namespace App\Widget\GuildEvent;

use App\Entity\GuildEvent;
use App\Entity\GuildMember;
use App\Entity\User;
use App\Repository\GuildEventRepository;
use Doctrine\ORM\Query\Expr\Join;

final readonly class UpcomingGuildEventsQuery
{
    public const MAX_RESULTS = 12;

    public function __construct(private GuildEventRepository $events)
    {
    }

    /**
     * @return list<GuildEvent>
     */
    public function findForUser(User $user, int $limit = 6, ?\DateTimeImmutable $after = null): array
    {
        $limit = max(1, min(self::MAX_RESULTS, $limit));
        $after ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->events->createQueryBuilder('event')
            ->select('DISTINCT event')
            ->innerJoin('event.guild', 'guild')
            ->innerJoin('guild.game', 'game')
            ->leftJoin('event.team', 'team')
            ->leftJoin('team.members', 'teamMember')
            ->leftJoin('team.leader', 'teamLeader')
            ->innerJoin(GuildMember::class, 'membership', Join::WITH, 'membership.guild = guild')
            ->andWhere('membership.user = :user')
            ->andWhere('membership.active = true')
            ->andWhere('guild.enabled = true')
            ->andWhere('game.enabled = true')
            ->andWhere('event.status = :planned')
            ->andWhere('event.startsAt >= :after')
            ->andWhere('(event.team IS NULL OR teamMember = membership OR teamLeader = membership)')
            ->setParameter('user', $user)
            ->setParameter('planned', GuildEvent::STATUS_PLANNED)
            ->setParameter('after', $after)
            ->orderBy('event.startsAt', 'ASC')
            ->addOrderBy('event.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

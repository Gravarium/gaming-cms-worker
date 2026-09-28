<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuildEventSignup;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class GuildEventSignupPortalRepository
{
    public const PAGE_SIZE = 25;
    public const WIDGET_LIMIT = 5;

    /** @var list<string> */
    private const ACTIVE_RESPONSES = [
        GuildEventSignup::GOING,
        GuildEventSignup::MAYBE,
        GuildEventSignup::WAITLIST,
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function countUpcomingForUser(User $user, \DateTimeImmutable $now): int
    {
        $count = $this->upcomingQuery($user, $now)
            ->select('COUNT(signup.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * @return list<array{id:int, guildName:string, characterName:string, eventTitle:string, startsAt:\DateTimeImmutable, response:string, role:string}>
     */
    public function pageUpcomingForUser(User $user, \DateTimeImmutable $now, int $page): array
    {
        $safePage = max(1, min(1_000_000, $page));
        $rows = $this->upcomingQuery($user, $now)
            ->select(
                'signup.id AS id',
                'guild.name AS guildName',
                'guildMember.characterName AS characterName',
                'event.title AS eventTitle',
                'event.startsAt AS startsAt',
                'signup.response AS response',
                'signup.role AS role',
            )
            ->orderBy('event.startsAt', 'ASC')
            ->addOrderBy('guild.name', 'ASC')
            ->addOrderBy('guildMember.characterName', 'ASC')
            ->addOrderBy('signup.id', 'ASC')
            ->setFirstResult(($safePage - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getArrayResult();

        /** @var list<array{id:int, guildName:string, characterName:string, eventTitle:string, startsAt:\DateTimeImmutable, response:string, role:string}> $rows */
        return $rows;
    }

    /**
     * @return list<array{id:int, guildName:string, characterName:string, eventTitle:string, startsAt:\DateTimeImmutable, response:string, role:string}>
     */
    public function upcomingForUser(User $user, \DateTimeImmutable $now, int $limit = self::WIDGET_LIMIT): array
    {
        $safeLimit = max(1, min(self::PAGE_SIZE, $limit));
        $rows = $this->upcomingQuery($user, $now)
            ->select(
                'signup.id AS id',
                'guild.name AS guildName',
                'guildMember.characterName AS characterName',
                'event.title AS eventTitle',
                'event.startsAt AS startsAt',
                'signup.response AS response',
                'signup.role AS role',
            )
            ->orderBy('event.startsAt', 'ASC')
            ->addOrderBy('guild.name', 'ASC')
            ->addOrderBy('guildMember.characterName', 'ASC')
            ->addOrderBy('signup.id', 'ASC')
            ->setMaxResults($safeLimit)
            ->getQuery()
            ->getArrayResult();

        /** @var list<array{id:int, guildName:string, characterName:string, eventTitle:string, startsAt:\DateTimeImmutable, response:string, role:string}> $rows */
        return $rows;
    }

    public function upcomingSignupForUser(int $signupId, User $user, \DateTimeImmutable $now): ?GuildEventSignup
    {
        $signup = $this->upcomingQuery($user, $now)
            ->select('signup')
            ->andWhere('signup.id = :signupId')
            ->setParameter('signupId', $signupId)
            ->getQuery()
            ->getOneOrNullResult();

        return $signup instanceof GuildEventSignup ? $signup : null;
    }

    private function upcomingQuery(User $user, \DateTimeImmutable $now): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from(GuildEventSignup::class, 'signup')
            ->innerJoin('signup.member', 'guildMember')
            ->innerJoin('signup.event', 'event')
            ->innerJoin('event.guild', 'guild')
            ->where('signup.user = :user')
            ->andWhere('guildMember.user = :user')
            ->andWhere('guildMember.active = true')
            ->andWhere('guild.enabled = true')
            ->andWhere('IDENTITY(event.guild) = IDENTITY(guildMember.guild)')
            ->andWhere('event.status = :planned')
            ->andWhere('event.startsAt > :now')
            ->andWhere('signup.response IN (:responses)')
            ->setParameter('user', $user)
            ->setParameter('planned', \App\Entity\GuildEvent::STATUS_PLANNED)
            ->setParameter('now', $now)
            ->setParameter('responses', self::ACTIVE_RESPONSES);
    }
}

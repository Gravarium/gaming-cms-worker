<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guild\GuildOnboardingTask;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GuildOnboardingPortalRepository
{
    public const PAGE_SIZE = 25;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function countForUser(User $user): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(task.id)')
            ->from(GuildOnboardingTask::class, 'task')
            ->innerJoin('task.member', 'guildMember')
            ->innerJoin('task.guild', 'guild')
            ->where('guildMember.user = :user')
            ->andWhere('guildMember.active = true')
            ->andWhere('guild.enabled = true')
            ->andWhere('IDENTITY(task.guild) = IDENTITY(guildMember.guild)')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pageForUser(User $user, int $page): array
    {
        $page = max(1, min(1_000_000, $page));
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'task.id AS id',
                'IDENTITY(task.guild) AS guildId',
                'guild.name AS guildName',
                'guildMember.characterName AS characterName',
                'task.label AS label',
                'task.completed AS completed',
                'actor.displayName AS completedBy',
                'task.completedAt AS completedAt',
            )
            ->from(GuildOnboardingTask::class, 'task')
            ->innerJoin('task.member', 'guildMember')
            ->innerJoin('task.guild', 'guild')
            ->leftJoin('task.completedBy', 'actor')
            ->where('guildMember.user = :user')
            ->andWhere('guildMember.active = true')
            ->andWhere('guild.enabled = true')
            ->andWhere('IDENTITY(task.guild) = IDENTITY(guildMember.guild)')
            ->setParameter('user', $user)
            ->orderBy('task.completed', 'ASC')
            ->addOrderBy('guild.name', 'ASC')
            ->addOrderBy('guildMember.characterName', 'ASC')
            ->addOrderBy('task.id', 'ASC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getArrayResult();

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    public function taskForUser(int $taskId, User $user): ?GuildOnboardingTask
    {
        $task = $this->entityManager->createQueryBuilder()
            ->select('task')
            ->from(GuildOnboardingTask::class, 'task')
            ->innerJoin('task.member', 'guildMember')
            ->innerJoin('task.guild', 'guild')
            ->where('task.id = :taskId')
            ->andWhere('guildMember.user = :user')
            ->andWhere('guildMember.active = true')
            ->andWhere('guild.enabled = true')
            ->andWhere('IDENTITY(task.guild) = IDENTITY(guildMember.guild)')
            ->setParameter('taskId', $taskId)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        return $task instanceof GuildOnboardingTask ? $task : null;
    }
}

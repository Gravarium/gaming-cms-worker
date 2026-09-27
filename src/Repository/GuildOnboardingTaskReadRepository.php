<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guild;
use App\Entity\GuildOnboardingTask;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GuildOnboardingTaskReadRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<array<string, mixed>> */
    public function forGuild(Guild $guild): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select(
                'task.id AS id',
                'task.label AS label',
                'member.characterName AS characterName',
                'task.completed AS completed',
                'actor.displayName AS completedBy',
                'task.completedAt AS completedAt',
            )
            ->from(GuildOnboardingTask::class, 'task')
            ->innerJoin('task.member', 'member')
            ->leftJoin('task.completedBy', 'actor')
            ->andWhere('task.guild = :guild')
            ->setParameter('guild', $guild)
            ->orderBy('task.completed', 'ASC')
            ->addOrderBy('task.id', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guild;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GuildOnboardingTaskReadRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<array<string, mixed>> */
    public function forGuild(Guild $guild): array
    {
        $guildId = $guild->getId();
        if ($guildId === null) {
            return [];
        }

        $rows = $this->entityManager->getConnection()->createQueryBuilder()
            ->select(
                'task.id AS id',
                'task.label AS label',
                'guild_member.character_name AS characterName',
                'task.completed AS completed',
                'actor.display_name AS completedBy',
                'task.completed_at AS completedAt',
            )
            ->from('guild_onboarding_task', 'task')
            ->innerJoin('task', 'guild_member', 'guild_member', 'guild_member.id = task.member_id')
            ->leftJoin('task', 'cms_user', 'actor', 'actor.id = task.completed_by_id')
            ->where('task.guild_id = :guildId')
            ->setParameter('guildId', $guildId, ParameterType::INTEGER)
            ->orderBy('task.completed', 'ASC')
            ->addOrderBy('task.id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }
}

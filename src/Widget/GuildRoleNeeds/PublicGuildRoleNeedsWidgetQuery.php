<?php

declare(strict_types=1);

namespace App\Widget\GuildRoleNeeds;

use App\Entity\Guild;
use App\Entity\Guild\GuildRoleNeed;
use App\Module\CmsModuleManager;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PublicGuildRoleNeedsWidgetQuery
{
    public const MAX_RESULTS = 12;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CmsModuleManager $modules,
    ) {
    }

    /**
     * @return list<array{guild: Guild, need: GuildRoleNeed}>
     */
    public function upcoming(int $limit = self::MAX_RESULTS): array
    {
        if (!$this->modules->isEnabled('gaming')) {
            return [];
        }

        $limit = max(1, min(self::MAX_RESULTS, $limit));

        $builder = $this->entityManager->createQueryBuilder()
            ->select('need', 'guild', 'guildGame')
            ->from(GuildRoleNeed::class, 'need')
            ->join('need.guild', 'guild')
            ->join('guild.game', 'guildGame')
            ->andWhere('need.game = guildGame')
            ->andWhere('need.active = true')
            ->andWhere('need.desiredCount > 0')
            ->andWhere('guild.enabled = true')
            ->andWhere('guild.recruitmentOpen = true')
            ->andWhere('guildGame.enabled = true')
            ->orderBy('guild.name', 'ASC')
            ->addOrderBy('guild.id', 'ASC')
            ->addOrderBy('need.roleKey', 'ASC')
            ->addOrderBy('need.classKey', 'ASC')
            ->setMaxResults($limit);

        /** @var list<GuildRoleNeed> $needs */
        $needs = $builder->getQuery()->getResult();

        return array_map(
            static fn (GuildRoleNeed $need): array => [
                'guild' => $need->getGuild(),
                'need' => $need,
            ],
            $needs,
        );
    }
}

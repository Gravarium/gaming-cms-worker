<?php

declare(strict_types=1);

namespace App\GuildRecruitment;

use App\Entity\Guild;
use App\Repository\GuildRoleNeedRepository;
use App\Module\CmsModuleManager;

/**
 * Applies the public recruitment and module gates before exposing role-needs data.
 */
final readonly class PublicGuildRoleNeedQuery
{
    public function __construct(
        private GuildRoleNeedRepository $needs,
        private CmsModuleManager $modules,
    ) {
    }

    /** @return list<\App\Entity\GuildRoleNeed> */
    public function forGuild(Guild $guild): array
    {
        if (
            !$this->modules->isEnabled('gaming')
            || !$guild->isEnabled()
            || !$guild->isRecruitmentOpen()
            || !$guild->getGame()?->isEnabled()
        ) {
            return [];
        }

        return $this->needs->findPublicActiveForGuild($guild);
    }
}

<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\User;
use App\Repository\GuildMemberRepository;
use App\Security\CmsPermission;

final readonly class SearchViewerFactory
{
    public function __construct(private GuildMemberRepository $members)
    {
    }

    public function fromUser(?User $user): SearchViewer
    {
        if (!$user instanceof User || !$user->isActive() || $user->getId() === null) {
            return new SearchViewer(null, false, false, []);
        }

        $guildIds = [];
        foreach ($this->members->forUser($user) as $member) {
            $guildId = $member->getGuild()?->getId();
            if ($guildId !== null) {
                $guildIds[] = $guildId;
            }
        }

        return new SearchViewer(
            $user->getId(),
            true,
            $user->isAdmin() || $user->hasPermission(CmsPermission::GAMING),
            array_values(array_unique($guildIds)),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Guild;
use App\GuildRecruitment\PublicGuildRoleNeedQuery;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PublicGuildRoleNeedExtension extends AbstractExtension
{
    public function __construct(private readonly PublicGuildRoleNeedQuery $query)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('public_guild_role_needs', [$this->query, 'forGuild']),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Widget;

use App\Entity\Guild\GuildRoleNeed;
use App\Widget\GuildRoleNeeds\PublicGuildRoleNeedsWidgetQuery;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class PublicGuildRoleNeedsWidgetProvider implements WidgetProvider
{
    private const KEY = 'gaming.public-guild-role-needs';
    private const CACHE_KEY = '_cms_widget_data_gaming.public-guild-role-needs';

    public function __construct(
        private PublicGuildRoleNeedsWidgetQuery $needs,
        private RequestStack $requests,
    ) {
    }

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                self::KEY,
                'Öffentliche Gilden-Rollenbedarfe',
                'gaming',
                'widget/public_guild_role_needs.html.twig',
            ),
        ];
    }

    /**
     * @param array<string, string|int|bool> $config
     *
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        if ($key !== self::KEY) {
            return [];
        }

        $request = $this->requests->getCurrentRequest();
        /** @var list<array{guild: \App\Entity\Guild, need: GuildRoleNeed}>|null $needs */
        $needs = $request?->attributes->get(self::CACHE_KEY);
        if (!is_array($needs)) {
            $needs = $this->needs->upcoming(PublicGuildRoleNeedsWidgetQuery::MAX_RESULTS);
            $request?->attributes->set(self::CACHE_KEY, $needs);
        }

        $count = $config['count'] ?? 6;
        $count = is_int($count) ? max(1, min(PublicGuildRoleNeedsWidgetQuery::MAX_RESULTS, $count)) : 6;

        return ['needs' => array_slice($needs, 0, $count)];
    }
}

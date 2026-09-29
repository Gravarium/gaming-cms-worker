<?php

declare(strict_types=1);

namespace App\Widget\Module;

use Doctrine\DBAL\Connection;

final readonly class ModuleWidgetGuildPresenceQuery
{
    public const ONLINE_WINDOW_SECONDS = 300;

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<int> */
    public function guildIdsForUser(?int $userId): array
    {
        if ($userId === null || $userId < 1) {
            return [];
        }

        $values = $this->connection->fetchFirstColumn(
            'SELECT guild_id FROM guild_member
             WHERE user_id = :user_id AND active = TRUE
             ORDER BY guild_id ASC
             LIMIT :limit',
            ['user_id' => $userId, 'limit' => 500],
        );

        $guildIds = [];
        foreach ($values as $value) {
            $guildId = filter_var($value, FILTER_VALIDATE_INT);
            if ($guildId !== false && $guildId > 0) {
                $guildIds[] = $guildId;
            }
        }

        return array_values(array_unique($guildIds));
    }

    public function forViewer(ModuleWidgetViewer $viewer, \DateTimeImmutable $now, int $limit): ModuleWidgetPayload
    {
        $guildIds = $viewer->guildIds();
        $viewerUserId = $viewer->userId;
        if (!$viewer->authenticated || $viewerUserId === null || $guildIds === []) {
            return ModuleWidgetPayload::noItems('guild_membership_required');
        }

        $limit = max(1, min(ModuleWidgetPayload::MAX_ITEMS, $limit));
        $guildParameters = [];
        $guildPlaceholders = [];
        foreach ($guildIds as $index => $guildId) {
            $name = 'guild_'.$index;
            $guildPlaceholders[] = ':'.$name;
            $guildParameters[$name] = $guildId;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT user_row.display_name, guild.name AS guild_name, game.name AS game_name
             FROM guild_member gm
             INNER JOIN cms_user user_row ON user_row.id = gm.user_id
             INNER JOIN guild ON guild.id = gm.guild_id
             INNER JOIN game ON game.id = guild.game_id
             LEFT JOIN member_profile profile ON profile.user_id = user_row.id
             WHERE gm.guild_id IN ('.implode(', ', $guildPlaceholders).')
               AND gm.active = TRUE
               AND EXISTS (
                    SELECT 1 FROM guild_member viewer_membership
                    WHERE viewer_membership.guild_id IN ('.implode(', ', $guildPlaceholders).')
                      AND viewer_membership.user_id = :viewer_user_id
                      AND viewer_membership.active = TRUE
               )
               AND user_row.is_active = TRUE
               AND (user_row.locked_until IS NULL OR user_row.locked_until <= :now)
               AND user_row.last_seen_at >= :cutoff
               AND guild.enabled = TRUE
               AND game.enabled = TRUE
               AND COALESCE(profile.visibility->>\'display_name\', \'public\') IN (\'public\', \'members\')
             ORDER BY user_row.last_seen_at DESC, user_row.id, gm.guild_id
             LIMIT :limit',
            [
                ...$guildParameters,
                'viewer_user_id' => $viewerUserId,
                'now' => $now->format('Y-m-d H:i:s'),
                'cutoff' => $now->modify('-'.self::ONLINE_WINDOW_SECONDS.' seconds')->format('Y-m-d H:i:s'),
                'limit' => $limit,
            ],
        );

        $items = [];
        foreach ($rows as $row) {
            if (!is_string($row['display_name'] ?? null)
                || !is_string($row['guild_name'] ?? null)
                || !is_string($row['game_name'] ?? null)
            ) {
                continue;
            }
            $items[] = [
                'title' => $this->bounded($row['display_name']),
                'summary' => $this->bounded($row['guild_name']),
                'eyebrow' => $this->bounded($row['game_name']),
                'badge' => 'Jetzt aktiv',
            ];
        }

        return $items === [] ? ModuleWidgetPayload::noItems() : ModuleWidgetPayload::ready($items);
    }

    private function bounded(string $value): string
    {
        return strlen($value) <= ModuleWidgetPayload::MAX_STRING_BYTES
            ? $value
            : mb_strcut($value, 0, ModuleWidgetPayload::MAX_STRING_BYTES, 'UTF-8');
    }
}

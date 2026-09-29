<?php

declare(strict_types=1);

namespace App\Widget\Module;

use Doctrine\DBAL\Connection;

final readonly class ModuleWidgetServerStatusQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function latest(\DateTimeImmutable $now, int $limit): ModuleWidgetPayload
    {
        $limit = max(1, min(ModuleWidgetPayload::MAX_ITEMS, $limit));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT registry.name AS server_name, registry.maintenance, game.name AS game_name,
                    latest.state, latest.players, latest.slots, latest.ping_ms, latest.map_name, latest.server_version
             FROM game_server_registry registry
             INNER JOIN game ON game.id = registry.game_id
             LEFT JOIN game_server_status latest
                ON latest.id = (
                    SELECT current_status.id
                    FROM game_server_status current_status
                    WHERE current_status.server_id = registry.id AND current_status.expires_at > :now
                    ORDER BY current_status.observed_at DESC, current_status.id DESC
                    LIMIT 1
                )
             WHERE registry.enabled = TRUE AND game.enabled = TRUE
             ORDER BY game.name, registry.name, registry.id
             LIMIT :limit',
            ['now' => $now->format('Y-m-d H:i:s'), 'limit' => $limit],
        );

        $items = [];
        foreach ($rows as $row) {
            $serverName = $row['server_name'] ?? null;
            $gameName = $row['game_name'] ?? null;
            if (!is_string($serverName) || !is_string($gameName)) {
                continue;
            }

            $state = $row['state'] ?? null;
            $summary = [];
            if (!in_array($state, ['online', 'offline', 'unknown'], true)) {
                $state = 'unknown';
                $summary[] = 'Status unbekannt';
            } elseif ($state === 'online') {
                $players = filter_var($row['players'] ?? null, FILTER_VALIDATE_INT);
                $slots = filter_var($row['slots'] ?? null, FILTER_VALIDATE_INT);
                if ($players !== false && $slots !== false && $players >= 0 && $slots >= $players) {
                    $summary[] = $players.'/'.$slots.' Spieler';
                }
                $summary[] = 'Online';
            } elseif ($state === 'offline') {
                $summary[] = 'Offline';
            } else {
                $summary[] = 'Status unbekannt';
            }

            $ping = filter_var($row['ping_ms'] ?? null, FILTER_VALIDATE_INT);
            if ($ping !== false && $ping >= 0 && $ping <= 120000) {
                $summary[] = $ping.' ms';
            }
            foreach (['map_name', 'server_version'] as $field) {
                if (is_string($row[$field] ?? null) && trim($row[$field]) !== '') {
                    $summary[] = trim($row[$field]);
                }
            }

            $items[] = [
                'title' => $this->bounded($serverName),
                'summary' => $this->bounded(implode(' · ', $summary)),
                'eyebrow' => $this->bounded($gameName),
                'badge' => $this->isTrue($row['maintenance'] ?? false) ? 'Wartung' : '',
            ];
        }

        return $items === [] ? ModuleWidgetPayload::noItems() : ModuleWidgetPayload::ready($items);
    }

    private function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    private function bounded(string $value): string
    {
        return strlen($value) <= ModuleWidgetPayload::MAX_STRING_BYTES
            ? $value
            : mb_strcut($value, 0, ModuleWidgetPayload::MAX_STRING_BYTES, 'UTF-8');
    }
}

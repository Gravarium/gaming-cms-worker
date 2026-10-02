<?php

declare(strict_types=1);

namespace App\VideoWorkspace;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class OwnedCollectionGateway
{
    private const TABLES = [
        'watchlist' => ['video_discovery_watchlist', 'user_id'],
        'comment' => ['video_discovery_timestamp_comment', 'author_id'],
        'clip' => ['video_discovery_clip', 'created_by_id'],
    ];
    public function __construct(private Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listing(string $kind, int $owner, int $page): array
    {
        [$table, $column] = $this->table($kind);
        return $this->db->fetchAllAssociative('SELECT * FROM '.$table.' WHERE '.$column.' = ? ORDER BY id DESC LIMIT 20 OFFSET '.(($page - 1) * 20), [$owner]);
    }
    /** @return array<string,mixed> */
    public function item(string $kind, int $id, int $owner, bool $lock = false): array
    {
        [$table, $column] = $this->table($kind);
        $suffix = $lock && $this->db->getDatabasePlatform() instanceof PostgreSQLPlatform ? ' FOR UPDATE' : '';
        $row = $this->db->fetchAssociative('SELECT * FROM '.$table.' WHERE id = ? AND '.$column.' = ?'.$suffix, [$id, $owner]);
        if ($row === false) { throw new NotFoundHttpException(); }
        return $row;
    }
    /** @return list<array<string,mixed>> */
    public function watchlistItems(int $id): array
    {
        return $this->db->fetchAllAssociative('SELECT i.id, v.slug, CASE WHEN v.enabled = ? AND v.published_at <= ? THEN v.title ELSE ? END AS title FROM video_discovery_watchlist_item i JOIN video v ON v.id = i.video_id WHERE i.watchlist_id = ? ORDER BY i.id DESC LIMIT 100', [true, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'Nicht verfügbar', $id]);
    }
    /** @param array<string,mixed> $row */
    public function version(array $row): string { return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)); }

    /** @param array<string,string|int|bool> $changes */
    public function mutate(string $kind, int $id, int $owner, string $version, string $action, array $changes = [], ?int $itemId = null): void
    {
        try {
            $this->db->transactional(function () use ($kind, $id, $owner, $version, $action, $changes, $itemId): void {
                $row = $this->item($kind, $id, $owner, true);
                if (!hash_equals($this->version($row), $version)) { throw new ConflictHttpException('Die Daten wurden inzwischen geändert. Bitte neu laden.'); }
                [$table, $column] = $this->table($kind);
                if ($action === 'delete') {
                    if ($kind === 'watchlist') { $this->db->delete('video_discovery_watchlist_item', ['watchlist_id' => $id]); }
                    $this->db->delete($table, ['id' => $id, $column => $owner]);
                    return;
                }
                if ($kind === 'watchlist' && $action === 'remove' && $itemId !== null) {
                    if ($this->db->delete('video_discovery_watchlist_item', ['id' => $itemId, 'watchlist_id' => $id]) !== 1) { throw new NotFoundHttpException(); }
                    return;
                }
                $allowed = match ($kind) {
                    'watchlist' => ['name', 'public'],
                    'comment' => ['body', 'timestamp_seconds', 'visibility'],
                    'clip' => ['title', 'start_seconds', 'end_seconds', 'visibility'],
                    default => [],
                };
                if ($action !== 'edit' || $changes === [] || array_diff(array_keys($changes), $allowed) !== []) { throw new \InvalidArgumentException('Ungültige Änderung.'); }
                $this->db->update($table, $changes, ['id' => $id, $column => $owner], $kind === 'watchlist' ? ['public' => \Doctrine\DBAL\Types\Types::BOOLEAN] : []);
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException('Eine Watchlist mit diesem Namen existiert bereits.', $exception);
        }
    }
    /** @return array{string,string} */
    private function table(string $kind): array
    {
        return self::TABLES[$kind] ?? throw new NotFoundHttpException();
    }
}

<?php

declare(strict_types=1);

namespace App\VideoWorkspace;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class LegacyLiveManagement
{
    public function __construct(private Connection $db) {}
    /** @return array<string,mixed> */
    public function find(int $id): array { return $this->db->fetchAssociative('SELECT * FROM video_discovery_live_stream WHERE id = ?', [$id]) ?: throw new NotFoundHttpException(); }
    /** @param array<string,mixed> $row */
    public function version(array $row): string { return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)); }
    /** @param array<string,mixed> $changes */
    public function mutate(int $id, string $version, array $changes, bool $delete): void
    {
        $this->db->transactional(function () use ($id, $version, $changes, $delete): void {
            $lock = $this->db->getDatabasePlatform() instanceof PostgreSQLPlatform ? ' FOR UPDATE' : '';
            $row = $this->db->fetchAssociative('SELECT * FROM video_discovery_live_stream WHERE id = ?'.$lock, [$id]);
            if ($row === false) { throw new NotFoundHttpException(); }
            if (!hash_equals($this->version($row), $version)) { throw new ConflictHttpException('Der Stream wurde inzwischen geändert.'); }
            if ($delete) { $this->db->delete('video_discovery_live_stream', ['id' => $id]); }
            else {
                if (array_diff(array_keys($changes), ['title', 'provider', 'source_url', 'creator_id', 'enabled', 'starts_at']) !== []) { throw new \InvalidArgumentException(); }
                $this->db->update('video_discovery_live_stream', $changes, ['id' => $id]);
            }
        });
    }
}

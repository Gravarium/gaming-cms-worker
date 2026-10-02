<?php

declare(strict_types=1);

namespace App\VideoWorkspace;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class CreatorManagement
{
    public function __construct(private Connection $db) {}
    /** @return array<string,mixed> */
    public function find(int $id): array
    {
        return $this->db->fetchAssociative('SELECT * FROM video_discovery_creator WHERE id = ?', [$id]) ?: throw new NotFoundHttpException();
    }
    /** @param array<string,mixed> $row */
    public function version(array $row): string { return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)); }
    /** @param array<string,string> $changes */
    public function mutate(int $id, string $version, array $changes, bool $delete): void
    {
        try {
            $this->db->transactional(function () use ($id, $version, $changes, $delete): void {
                $lock = $this->db->getDatabasePlatform() instanceof PostgreSQLPlatform ? ' FOR UPDATE' : '';
                $row = $this->db->fetchAssociative('SELECT * FROM video_discovery_creator WHERE id = ?'.$lock, [$id]);
                if ($row === false) { throw new NotFoundHttpException(); }
                if (!hash_equals($this->version($row), $version)) { throw new ConflictHttpException('Das Profil wurde inzwischen geändert.'); }
                if ($delete) {
                    foreach (['video_discovery_profile', 'video_discovery_live_stream', 'video_workspace_source'] as $table) {
                        if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE creator_id = ?', [$id]) > 0) {
                            throw new ConflictHttpException('Zuerst die zugeordneten Videos und Streams neu zuordnen.');
                        }
                    }
                    $this->db->delete('video_discovery_creator', ['id' => $id]);
                } else {
                    if (array_diff(array_keys($changes), ['display_name', 'bio', 'visibility']) !== []) { throw new \InvalidArgumentException(); }
                    $this->db->update('video_discovery_creator', $changes, ['id' => $id]);
                }
            });
        } catch (UniqueConstraintViolationException $e) { throw new ConflictHttpException('Dieses Profil existiert bereits.', $e); }
    }
}

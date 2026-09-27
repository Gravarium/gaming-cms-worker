<?php

declare(strict_types=1);

namespace App\GameGuide;

use App\Gaming\Guide\GuideVersion;
use App\Module\CmsModuleManager;
use Doctrine\DBAL\Connection;

final readonly class PublicGameGuideQuery
{
    public const PAGE_SIZE = 24;
    public const MAX_RESULTS = 12;

    public function __construct(
        private Connection $connection,
        private CmsModuleManager $modules,
    ) {
    }

    /** @return list<array{id:int,name:string,slug:string}> */
    public function enabledGames(): array
    {
        if (!$this->modules->isEnabled('gaming')) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, slug FROM game WHERE enabled = true ORDER BY name ASC, id ASC',
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
            ],
            $rows,
        );
    }

    public function hasEnabledGame(string $slug): bool
    {
        if (!$this->modules->isEnabled('gaming') || $slug === '') {
            return false;
        }

        return $this->connection->fetchOne(
            'SELECT id FROM game WHERE slug = :slug AND enabled = true',
            ['slug' => $slug],
        ) !== false;
    }

    public function count(string $query = '', string $type = '', string $gameSlug = ''): int
    {
        if (!$this->modules->isEnabled('gaming')) {
            return 0;
        }
        $params = [];
        $where = $this->where($query, $type, $gameSlug, $params);

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM game_guide guide INNER JOIN game ON game.id = guide.game_id WHERE '.$where,
            $params,
        );
    }

    /**
     * @return list<array{id:int,title:string,guide_type:string,game_version:string,season:string,valid_from:string,valid_until:?string,published_at:string,game_name:string,game_slug:string,is_outdated:bool}>
     */
    public function directory(string $query = '', string $type = '', string $gameSlug = '', int $limit = self::PAGE_SIZE, int $offset = 0): array
    {
        if (!$this->modules->isEnabled('gaming')) {
            return [];
        }
        $limit = max(1, min(self::PAGE_SIZE, $limit));
        $offset = max(0, min(240_000, $offset));
        $params = [];
        $where = $this->where($query, $type, $gameSlug, $params);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT guide.id, guide.title, guide.guide_type, guide.game_version, guide.season,
                    guide.valid_from, guide.valid_until, guide.published_at, game.name AS game_name, game.slug AS game_slug
             FROM game_guide guide
             INNER JOIN game ON game.id = guide.game_id
             WHERE '.$where.'
             ORDER BY guide.published_at DESC, guide.id DESC
             LIMIT '.$limit.' OFFSET '.$offset,
            $params,
        );

        return array_map(fn (array $row): array => $this->summary($row), $rows);
    }

    /** @return list<array{id:int,title:string,guide_type:string,game_version:string,season:string,valid_from:string,valid_until:?string,published_at:string,game_name:string,game_slug:string,is_outdated:bool}> */
    public function latest(int $limit = 6): array
    {
        return $this->directory('', '', '', max(1, min(self::MAX_RESULTS, $limit)), 0);
    }

    /**
     * @return (array{id:int,title:string,guide_type:string,game_version:string,season:string,valid_from:string,valid_until:?string,published_at:string,game_name:string,game_slug:string,is_outdated:bool,components:list<array{type:string,key:string,position:int,alternatives:list<string>}>,tiers:list<array{key:string,tier:string,reason:string,criteria:string,provenance:string}>})|null
     */
    public function findPublic(int $id): ?array
    {
        if (!$this->modules->isEnabled('gaming') || $id < 1) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            "SELECT guide.id, guide.title, guide.guide_type, guide.game_version, guide.season,
                    guide.valid_from, guide.valid_until, guide.published_at, game.name AS game_name, game.slug AS game_slug
             FROM game_guide guide
             INNER JOIN game ON game.id = guide.game_id
             WHERE guide.id = :id AND guide.review_status = 'published'
               AND guide.published_at IS NOT NULL AND guide.published_at <= CURRENT_TIMESTAMP
               AND game.enabled = true",
            ['id' => $id],
        );
        if ($row === false) {
            return null;
        }
        $summary = $this->summary($row);
        $componentRows = $this->connection->fetchAllAssociative(
            'SELECT component_type, component_key, position, alternatives
             FROM game_guide_component WHERE guide_id = :id ORDER BY position ASC, id ASC',
            ['id' => $id],
        );
        $tierRows = $this->connection->fetchAllAssociative(
            'SELECT entry_key, tier, reason, criteria, provenance
             FROM game_guide_tier_entry WHERE guide_id = :id ORDER BY entry_key ASC, id ASC',
            ['id' => $id],
        );

        return $summary + [
            'components' => array_map(
                fn (array $component): array => [
                    'type' => (string) $component['component_type'],
                    'key' => (string) $component['component_key'],
                    'position' => (int) $component['position'],
                    'alternatives' => $this->decodeStringList($component['alternatives']),
                ],
                $componentRows,
            ),
            'tiers' => array_map(
                static fn (array $tier): array => [
                    'key' => (string) $tier['entry_key'],
                    'tier' => (string) $tier['tier'],
                    'reason' => (string) $tier['reason'],
                    'criteria' => (string) $tier['criteria'],
                    'provenance' => (string) $tier['provenance'],
                ],
                $tierRows,
            ),
        ];
    }

    /** @param array<string, mixed> $params */
    private function where(string $query, string $type, string $gameSlug, array &$params): string
    {
        $conditions = [
            "guide.review_status = 'published'",
            'guide.published_at IS NOT NULL',
            'guide.published_at <= CURRENT_TIMESTAMP',
            'game.enabled = true',
        ];
        if ($query !== '') {
            $needle = mb_substr(mb_strtolower(trim($query)), 0, 100);
            $needle = strtr($needle, ['!' => '!!', '%' => '!%', '_' => '!_']);
            $conditions[] = "LOWER(guide.title) LIKE :query ESCAPE '!'";
            $params['query'] = '%'.$needle.'%';
        }
        if ($type !== '') {
            $conditions[] = 'guide.guide_type = :type';
            $params['type'] = $type;
        }
        if ($gameSlug !== '') {
            $conditions[] = 'game.slug = :game_slug';
            $params['game_slug'] = $gameSlug;
        }

        return implode(' AND ', $conditions);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id:int,title:string,guide_type:string,game_version:string,season:string,valid_from:string,valid_until:?string,published_at:string,game_name:string,game_slug:string,is_outdated:bool}
     */
    private function summary(array $row): array
    {
        $version = (string) $row['game_version'];
        $season = (string) $row['season'];
        $validFromText = (string) $row['valid_from'];
        $validUntilText = $row['valid_until'] === null ? null : (string) $row['valid_until'];
        $outdated = true;
        try {
            $guideVersion = new GuideVersion(
                $version,
                $season,
                new \DateTimeImmutable($validFromText),
                $validUntilText === null ? null : new \DateTimeImmutable($validUntilText),
            );
            $outdated = $guideVersion->isOutdated(new \DateTimeImmutable(), $version);
        } catch (\InvalidArgumentException) {
            // Invalid stored validity data is surfaced as stale rather than treated as current.
        }

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'guide_type' => (string) $row['guide_type'],
            'game_version' => $version,
            'season' => $season,
            'valid_from' => $validFromText,
            'valid_until' => $validUntilText,
            'published_at' => (string) $row['published_at'],
            'game_name' => (string) $row['game_name'],
            'game_slug' => (string) $row['game_slug'],
            'is_outdated' => $outdated,
        ];
    }

    /** @param mixed $value
     * @return list<string>
     */
    private function decodeStringList(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
        }
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}

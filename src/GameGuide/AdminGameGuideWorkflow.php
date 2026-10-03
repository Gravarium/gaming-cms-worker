<?php

declare(strict_types=1);

namespace App\GameGuide;

use App\Gaming\Guide\BuildComponent;
use App\Gaming\Guide\GuideReview;
use App\Gaming\Guide\GuideVersion;
use App\Gaming\Guide\StructuredBuild;
use App\Gaming\Guide\TierList;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;

final readonly class AdminGameGuideWorkflow
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array{id:int,name:string,slug:string}> */
    public function enabledGames(): array
    {
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

    /** @return list<array{id:int,title:string,guide_type:string,game_name:string,game_version:string,season:string,review_status:string,author_id:?int}> */
    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT guide.id, guide.title, guide.guide_type, game.name AS game_name, guide.game_version, guide.season, guide.review_status, guide.author_id
             FROM game_guide guide
             INNER JOIN game ON game.id = guide.game_id
             ORDER BY CASE guide.review_status WHEN 'review' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END, guide.id DESC
             LIMIT 100",
        );

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'guide_type' => (string) $row['guide_type'],
                'game_name' => (string) $row['game_name'],
                'game_version' => (string) $row['game_version'],
                'season' => (string) $row['season'],
                'review_status' => (string) $row['review_status'],
                'author_id' => $row['author_id'] === null ? null : (int) $row['author_id'],
            ],
            $rows,
        );
    }

    /** @return array<string, mixed>|null */
    public function editableDraft(int $id, int $authorId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT guide.*, game.name AS game_name
             FROM game_guide guide
             INNER JOIN game ON game.id = guide.game_id
             WHERE guide.id = :id AND guide.review_status = 'draft' AND guide.author_id = :author",
            ['id' => $id, 'author' => $authorId],
        );

        return $row === false ? null : $this->withStructure($row);
    }

    /** @return array<string, mixed>|null */
    public function reviewSubmission(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT guide.*, game.name AS game_name
             FROM game_guide guide
             INNER JOIN game ON game.id = guide.game_id
             WHERE guide.id = :id AND guide.review_status = 'review'",
            ['id' => $id],
        );

        return $row === false ? null : $this->withStructure($row);
    }

    /** @param array<string, mixed> $input */
    public function createDraft(int $authorId, array $input): int
    {
        if ($authorId < 1) {
            throw new \InvalidArgumentException('A saved editor account is required.');
        }
        $data = $this->validated($input);

        return $this->connection->transactional(function (Connection $connection) use ($authorId, $data): int {
            $this->assertEnabledGame($connection, $data['game_id']);
            $now = new \DateTimeImmutable();
            $id = (int) $connection->fetchOne(
                "INSERT INTO game_guide (game_id, author_id, reviewer_id, title, guide_type, game_version, season, valid_from, valid_until, review_status, published_at)
                 VALUES (:game, :author, NULL, :title, :type, :version, :season, :valid_from, :valid_until, 'draft', NULL)
                 RETURNING id",
                [
                    'game' => $data['game_id'],
                    'author' => $authorId,
                    'title' => $data['title'],
                    'type' => $data['guide_type'],
                    'version' => $data['game_version'],
                    'season' => $data['season'],
                    'valid_from' => $data['valid_from']->format('Y-m-d H:i:s'),
                    'valid_until' => $data['valid_until']?->format('Y-m-d H:i:s'),
                ],
            );
            $this->storeStructure($connection, $id, $data);
            $this->appendAudit($connection, $id, $authorId, 'draft', 'Draft created', $now);

            return $id;
        });
    }

    /** @param array<string, mixed> $input */
    public function updateDraft(int $id, int $authorId, array $input): bool
    {
        $data = $this->validated($input);

        return $this->connection->transactional(function (Connection $connection) use ($id, $authorId, $data): bool {
            $row = $connection->fetchAssociative(
                'SELECT author_id, review_status FROM game_guide WHERE id = :id',
                ['id' => $id],
            );
            if ($row === false || (int) $row['author_id'] !== $authorId || $row['review_status'] !== 'draft') {
                return false;
            }
            $this->assertEnabledGame($connection, $data['game_id']);

            $updated = $connection->executeStatement(
                "UPDATE game_guide
                 SET game_id = :game, title = :title, guide_type = :type, game_version = :version, season = :season,
                     valid_from = :valid_from, valid_until = :valid_until
                 WHERE id = :id AND review_status = 'draft' AND author_id = :author",
                [
                    'game' => $data['game_id'],
                    'title' => $data['title'],
                    'type' => $data['guide_type'],
                    'version' => $data['game_version'],
                    'season' => $data['season'],
                    'valid_from' => $data['valid_from']->format('Y-m-d H:i:s'),
                    'valid_until' => $data['valid_until']?->format('Y-m-d H:i:s'),
                    'id' => $id,
                    'author' => $authorId,
                ],
            );
            if ($updated !== 1) {
                return false;
            }
            $this->storeStructure($connection, $id, $data);

            return true;
        });
    }

    public function submit(int $id, int $authorId): bool
    {
        return $this->connection->transactional(function (Connection $connection) use ($id, $authorId): bool {
            $row = $connection->fetchAssociative(
                'SELECT author_id, review_status FROM game_guide WHERE id = :id',
                ['id' => $id],
            );
            if ($row === false || (int) $row['author_id'] !== $authorId || $row['review_status'] !== 'draft') {
                return false;
            }

            $now = new \DateTimeImmutable();
            (new GuideReview())->submit($authorId, $now);
            $changed = $connection->executeStatement(
                "UPDATE game_guide SET review_status = 'review' WHERE id = :id AND author_id = :author AND review_status = 'draft'",
                ['id' => $id, 'author' => $authorId],
            );
            if ($changed !== 1) {
                return false;
            }
            $this->appendAudit($connection, $id, $authorId, 'review', 'Submitted for review', $now);

            return true;
        });
    }

    public function decide(int $id, int $reviewerId, string $decision, string $reason): bool
    {
        if (!in_array($decision, ['publish', 'reject'], true)) {
            throw new \InvalidArgumentException('Choose publish or reject.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('A review reason of at most 500 characters is required.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($id, $reviewerId, $decision, $reason): bool {
            $row = $connection->fetchAssociative(
                "SELECT author_id, review_status
                 FROM game_guide
                 WHERE id = :id",
                ['id' => $id],
            );
            if ($row === false || $row['review_status'] !== 'review' || $row['author_id'] === null) {
                return false;
            }
            $authorId = (int) $row['author_id'];
            $submittedAt = $connection->fetchOne(
                "SELECT occurred_at FROM game_guide_review_audit
                 WHERE guide_id = :id AND status = 'review'
                 ORDER BY id DESC LIMIT 1",
                ['id' => $id],
            );
            if ($submittedAt === false) {
                throw new \DomainException('Guide submission has no review audit record.');
            }

            $review = new GuideReview();
            $review->submit($authorId, new \DateTimeImmutable((string) $submittedAt));
            $now = new \DateTimeImmutable();
            if ($decision === 'publish') {
                $review->approve($reviewerId, $authorId, $reason, $now);
                $status = 'published';
                $publishedAt = $now->format('Y-m-d H:i:s');
            } else {
                $review->reject($reviewerId, $reason, $now);
                $status = 'draft';
                $publishedAt = null;
            }

            $changed = $connection->executeStatement(
                "UPDATE game_guide SET review_status = :status, reviewer_id = :reviewer, published_at = :published
                 WHERE id = :id AND review_status = 'review'",
                ['status' => $status, 'reviewer' => $reviewerId, 'published' => $publishedAt, 'id' => $id],
            );
            if ($changed !== 1) {
                return false;
            }
            $this->appendAudit($connection, $id, $reviewerId, $status, $reason, $now);

            return true;
        });
    }

    public function withdrawPublished(int $id, int $editorId, string $reason): bool
    {
        if ($id < 1 || $editorId < 1) {
            throw new \InvalidArgumentException('A persisted guide and editor are required.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('A withdrawal reason of at most 500 characters is required.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($id, $editorId, $reason): bool {
            if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $lockedGuide = $connection->fetchAssociative(
                    "SELECT id FROM game_guide
                     WHERE id = :id AND review_status = 'published' AND author_id IS NOT NULL
                     FOR UPDATE",
                    ['id' => $id],
                );
                if ($lockedGuide === false) {
                    return false;
                }
            }

            $changed = $connection->executeStatement(
                "UPDATE game_guide SET review_status = 'draft', published_at = NULL
                 WHERE id = :id AND review_status = 'published' AND author_id IS NOT NULL",
                ['id' => $id],
            );
            if ($changed !== 1) {
                return false;
            }

            $this->appendAudit($connection, $id, $editorId, 'draft', $reason, new \DateTimeImmutable());

            return true;
        });
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withStructure(array $row): array
    {
        $components = $this->connection->fetchAllAssociative(
            'SELECT component_type, component_key, position, alternatives FROM game_guide_component WHERE guide_id = :id ORDER BY position ASC, id ASC',
            ['id' => (int) $row['id']],
        );
        $tiers = $this->connection->fetchAllAssociative(
            'SELECT entry_key, tier, reason, criteria, provenance FROM game_guide_tier_entry WHERE guide_id = :id ORDER BY entry_key ASC, id ASC',
            ['id' => (int) $row['id']],
        );

        $build = new StructuredBuild();
        foreach ($components as $component) {
            $build->add(new BuildComponent(
                (string) $component['component_type'],
                (string) $component['component_key'],
                (int) $component['position'],
                $this->decodeStringList($component['alternatives']),
            ));
        }
        $tierRows = array_map(
            static fn (array $tier): array => [
                'key' => (string) $tier['entry_key'],
                'tier' => (string) $tier['tier'],
                'reason' => (string) $tier['reason'],
                'criteria' => (string) $tier['criteria'],
                'provenance' => (string) $tier['provenance'],
            ],
            $tiers,
        );
        $componentRows = array_map(
            fn (array $component): array => [
                'type' => (string) $component['component_type'],
                'key' => (string) $component['component_key'],
                'position' => (int) $component['position'],
                'alternatives' => $this->decodeStringList($component['alternatives']),
            ],
            $components,
        );

        return array_merge($row, [
            'components' => $componentRows,
            'tiers' => $tierRows,
            'build_code' => $build->components() === [] ? '' : $build->exportCode(),
            'tier_entries_json' => $tierRows === [] ? '' : json_encode(array_map(
                static fn (array $tier): array => ['key' => $tier['key'], 'tier' => $tier['tier'], 'reason' => $tier['reason']],
                $tierRows,
            ), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            'tier_criteria' => (string) ($tiers[0]['criteria'] ?? ''),
            'tier_provenance' => (string) ($tiers[0]['provenance'] ?? ''),
            'valid_from' => substr((string) $row['valid_from'], 0, 10),
            'valid_until' => $row['valid_until'] === null ? '' : substr((string) $row['valid_until'], 0, 10),
        ]);
    }

    /** @param array<string, mixed> $input
     * @return array{game_id:int,title:string,guide_type:string,game_version:string,season:string,valid_from:\DateTimeImmutable,valid_until:?\DateTimeImmutable,components:list<BuildComponent>,tiers:list<array{key:string,tier:string,reason:string,criteria:string,provenance:string}>}
     */
    private function validated(array $input): array
    {
        $title = trim($this->field($input, 'title'));
        $game = trim($this->field($input, 'game_id'));
        $type = trim($this->field($input, 'guide_type'));
        $version = trim($this->field($input, 'game_version'));
        $season = trim($this->field($input, 'season'));
        $validFromText = trim($this->field($input, 'valid_from'));
        $validUntilText = trim($this->field($input, 'valid_until'));

        if ($title === '' || mb_strlen($title) > 180 || !ctype_digit($game) || (int) $game < 1) {
            throw new \InvalidArgumentException('Enter a title and select an enabled game.');
        }
        if (!in_array($type, ['build', 'tier_list'], true)) {
            throw new \InvalidArgumentException('Choose a build or tier list.');
        }
        $validFrom = $this->date($validFromText);
        $validUntil = $validUntilText === '' ? null : $this->date($validUntilText);
        new GuideVersion($version, $season, $validFrom, $validUntil);

        $components = [];
        $tiers = [];
        if ($type === 'build') {
            $code = $this->field($input, 'build_code');
            $build = StructuredBuild::importCode($code);
            $components = $build->components();
            if ($components === []) {
                throw new \InvalidArgumentException('Add at least one structured build component.');
            }
            foreach ($components as $component) {
                foreach ($component->alternatives as $alternative) {
                    if (mb_strlen($alternative) > 255) {
                        throw new \InvalidArgumentException('Build alternatives are limited to 255 characters.');
                    }
                }
            }
        } else {
            $criteria = trim($this->field($input, 'tier_criteria'));
            $provenance = trim($this->field($input, 'tier_provenance'));
            if (strlen($this->field($input, 'tier_entries_json')) > 32_000) {
                throw new \InvalidArgumentException('Tier-list data is too large.');
            }
            try {
                $decoded = json_decode($this->field($input, 'tier_entries_json'), true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \InvalidArgumentException('Tier-list entries must be valid JSON.', 0, $exception);
            }
            if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) < 1 || count($decoded) > 200) {
                throw new \InvalidArgumentException('Enter between 1 and 200 tier-list entries.');
            }
            $tierList = new TierList($criteria, $provenance);
            $seen = [];
            foreach ($decoded as $entry) {
                if (!is_array($entry)) {
                    throw new \InvalidArgumentException('Each tier-list entry must be an object.');
                }
                $key = $entry['key'] ?? null;
                $tier = $entry['tier'] ?? null;
                $reason = $entry['reason'] ?? null;
                if (!is_string($key) || !is_string($tier) || !is_string($reason) || mb_strlen($reason) > 2000 || isset($seen[$key])) {
                    throw new \InvalidArgumentException('Tier-list entry fields are invalid or duplicated.');
                }
                $tierList->rank($key, $tier, trim($reason));
                $seen[$key] = true;
                $tiers[] = [
                    'key' => $key,
                    'tier' => $tier,
                    'reason' => trim($reason),
                    'criteria' => $criteria,
                    'provenance' => $provenance,
                ];
            }
        }

        return [
            'game_id' => (int) $game,
            'title' => $title,
            'guide_type' => $type,
            'game_version' => $version,
            'season' => $season,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'components' => $components,
            'tiers' => $tiers,
        ];
    }

    /** @param array<string, mixed> $input */
    private function field(array $input, string $key): string
    {
        $value = $input[$key] ?? '';
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Form fields must contain text values.');
        }

        return $value;
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Use a valid calendar date.');
        }

        return $date;
    }

    private function assertEnabledGame(Connection $connection, int $gameId): void
    {
        if ($connection->fetchOne('SELECT id FROM game WHERE id = :id AND enabled = true', ['id' => $gameId]) === false) {
            throw new \InvalidArgumentException('The selected game is unavailable.');
        }
    }

    /** @param array{components:list<BuildComponent>,tiers:list<array{key:string,tier:string,reason:string,criteria:string,provenance:string}>} $data */
    private function storeStructure(Connection $connection, int $guideId, array $data): void
    {
        $connection->delete('game_guide_component', ['guide_id' => $guideId]);
        $connection->delete('game_guide_tier_entry', ['guide_id' => $guideId]);
        foreach ($data['components'] as $component) {
            $connection->executeStatement(
                'INSERT INTO game_guide_component (guide_id, component_type, component_key, position, alternatives)
                 VALUES (:guide, :type, :key, :position, :alternatives)',
                [
                    'guide' => $guideId,
                    'type' => $component->type,
                    'key' => $component->key,
                    'position' => $component->position,
                    'alternatives' => $component->alternatives,
                ],
                ['alternatives' => Types::JSON],
            );
        }
        foreach ($data['tiers'] as $tier) {
            $connection->insert('game_guide_tier_entry', [
                'guide_id' => $guideId,
                'entry_key' => $tier['key'],
                'tier' => $tier['tier'],
                'reason' => $tier['reason'],
                'criteria' => $tier['criteria'],
                'provenance' => $tier['provenance'],
            ]);
        }
    }

    private function appendAudit(Connection $connection, int $guideId, int $actorId, string $status, string $reason, \DateTimeImmutable $at): void
    {
        $connection->insert('game_guide_review_audit', [
            'guide_id' => $guideId,
            'actor_id' => $actorId,
            'status' => $status,
            'reason' => mb_substr($reason, 0, 500),
            'occurred_at' => $at->format('Y-m-d H:i:s'),
        ]);
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

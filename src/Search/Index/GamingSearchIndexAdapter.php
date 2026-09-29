<?php

declare(strict_types=1);

namespace App\Search\Index;

use App\Repository\Competition\CompetitionRepository;
use App\Repository\GameCatalogue\GameCatalogueEntryRepository;
use App\Repository\GuildRepository;
use App\Search\SearchIndexAdapter;
use App\Search\SearchIndexRecord;
use App\Search\SearchModuleAvailability;
use Doctrine\DBAL\Connection;

final readonly class GamingSearchIndexAdapter implements SearchIndexAdapter
{
    public function __construct(
        private GameCatalogueEntryRepository $catalogue,
        private GuildRepository $guilds,
        private CompetitionRepository $competitions,
        private Connection $connection,
        private SearchModuleAvailability $availability,
    ) {
    }

    public function moduleKey(): string { return 'gaming'; }

    /** @return list<string> */
    public function sourceTypes(): array
    {
        return ['game_catalogue', 'guild', 'competition', 'game_guide'];
    }

    /** @return iterable<SearchIndexRecord> */
    public function records(): iterable
    {
        if (!$this->availability->isEnabled($this->moduleKey())) {
            return;
        }

        foreach ($this->catalogue->publicEntries() as $entry) {
            if ($entry->getId() === null || !$entry->isPublic()) {
                continue;
            }
            $game = $entry->getGame();
            $facets = ['game', 'catalogue'];
            foreach ($entry->getGenres() as $genre) {
                $facets[] = 'genre:'.$genre->getSlug();
            }
            $body = trim(implode("\n", array_filter([
                $game->getDescription(),
                $entry->getSummary(),
                $entry->getDeveloper(),
                $entry->getPublisher()?->getName(),
            ], static fn (?string $value): bool => $value !== null && trim($value) !== '')));

            yield new SearchIndexRecord(
                'game_catalogue',
                $entry->getId(),
                $this->moduleKey(),
                'game',
                $game->getName(),
                $body === '' ? $game->getName() : $body,
                $entry->getSummary(),
                '/games/'.rawurlencode($game->getSlug()),
                'public',
                null,
                null,
                array_values(array_unique($facets)),
                15,
                false,
                self::stableCatalogueDate(),
            );
        }

        foreach ($this->guilds->findPublicGuilds() as $guild) {
            if ($guild->getId() === null || $guild->getGame() === null || !$guild->getGame()->isEnabled()) {
                continue;
            }
            $facets = ['guild'];
            if ($guild->isRecruitmentOpen()) {
                $facets[] = 'recruiting';
            }
            if ($guild->getRegion() !== null) {
                $facets[] = 'region:'.$guild->getRegion();
            }
            yield new SearchIndexRecord(
                'guild',
                $guild->getId(),
                $this->moduleKey(),
                'guild',
                $guild->getName(),
                trim(implode("\n", array_filter([
                    $guild->getDescription(),
                    $guild->getGame()->getName(),
                    $guild->getServerName(),
                    $guild->getRegion(),
                    $guild->getFaction(),
                ], static fn (?string $value): bool => $value !== null && trim($value) !== ''))),
                $guild->getDescription(),
                '/gaming/guild/'.rawurlencode($guild->getSlug()),
                'public',
                null,
                null,
                array_values(array_unique($facets)),
                $guild->isRecruitmentOpen() ? 25 : 10,
                false,
                $guild->getCreatedAt(),
            );
        }

        foreach ($this->competitions->recentForAdmin() as $competition) {
            if ($competition->getId() === null || $competition->getGame() === null || !$competition->getGame()->isEnabled()) {
                continue;
            }
            if ($competition->getStatus() === 'draft') {
                continue;
            }
            $private = $competition->getVisibility() === 'private';
            $facets = ['competition', $competition->getStatus()];
            if ($competition->getStartsAt() > new \DateTimeImmutable()) {
                $facets[] = 'upcoming';
            }
            $ownerId = $competition->getCreatedBy()?->getId();
            yield new SearchIndexRecord(
                'competition',
                $competition->getId(),
                $this->moduleKey(),
                'competition',
                $competition->getName(),
                trim($competition->getDescription().' '.$competition->getGame()->getName()),
                $competition->getDescription(),
                '/competitions/'.$competition->getId(),
                $private ? 'owner_or_moderator' : 'public',
                $ownerId,
                null,
                array_values(array_unique($facets)),
                $competition->getStartsAt() > new \DateTimeImmutable() ? 20 : 5,
                false,
                $competition->getCreatedAt(),
            );
        }

        yield from $this->guideRecords();
    }

    /** @return iterable<SearchIndexRecord> */
    private function guideRecords(): iterable
    {
        if (!$this->tableExists('game_guide')) {
            return;
        }

        $now = new \DateTimeImmutable();
        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->connection->fetchAllAssociative(
                'SELECT guide.id, guide.title, guide.guide_type, guide.game_version, guide.season, guide.valid_from, guide.valid_until, guide.published_at, game.name AS game_name, game.slug AS game_slug
                 FROM game_guide guide
                 INNER JOIN game ON game.id = guide.game_id
                 WHERE game.enabled = :enabled
                   AND guide.review_status = :published
                   AND guide.published_at IS NOT NULL
                   AND guide.published_at <= :now
                   AND guide.valid_from <= :now
                   AND (guide.valid_until IS NULL OR guide.valid_until > :now)
                 ORDER BY guide.published_at DESC, guide.id ASC',
                ['enabled' => true, 'published' => 'published', 'now' => $now],
            );
        } catch (\Throwable) {
            return;
        }

        foreach ($rows as $row) {
            $id = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $title = is_string($row['title'] ?? null) ? trim($row['title']) : '';
            $gameName = is_string($row['game_name'] ?? null) ? trim($row['game_name']) : '';
            if ($id < 1 || $title === '' || $gameName === '') {
                continue;
            }
            $version = is_string($row['game_version'] ?? null) ? trim($row['game_version']) : '';
            $season = is_string($row['season'] ?? null) ? trim($row['season']) : '';
            $gameSlug = is_string($row['game_slug'] ?? null) ? trim($row['game_slug']) : '';
            $guideType = is_string($row['guide_type'] ?? null) ? trim($row['guide_type']) : 'guide';
            $publishedAt = self::dateFromMixed($row['published_at'] ?? null, $now);
            $facets = ['guide', 'guide:'.$guideType, 'game:'.$gameSlug];
            if ($version !== '') {
                $facets[] = 'version:'.$version;
            }
            if ($season !== '') {
                $facets[] = 'season:'.$season;
            }

            yield new SearchIndexRecord(
                'game_guide',
                $id,
                $this->moduleKey(),
                'guide',
                $title,
                trim(implode("\n", array_filter([$title, $gameName, $guideType, $version, $season]))),
                $gameName.' · '.$version.' · '.$season,
                null,
                'public',
                null,
                null,
                array_values(array_unique($facets)),
                18,
                false,
                $publishedAt,
            );
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->connection->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function stableCatalogueDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2000-01-01 00:00:00+00:00');
    }

    private static function dateFromMixed(mixed $value, \DateTimeImmutable $fallback): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
            }
        }

        return $fallback;
    }
}

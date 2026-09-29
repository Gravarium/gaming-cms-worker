<?php

declare(strict_types=1);

namespace App\Widget\Module;

use App\Entity\Video;
use App\ForumWorkflow\ForumWorkflowGateway;
use App\Repository\GameCatalogue\GameReleaseRepository;
use App\Repository\GuildRepository;
use App\Repository\PublicCompetitionBracketRepository;
use App\Repository\VideoRepository;
use App\Widget\CompetitionStandings\CompetitionStandingsQuery;
use App\Widget\DownloadCatalogue\PublicDownloadCatalogueQuery;
use App\Widget\GameGuide\PublicGameGuideWidgetQuery;
use App\Widget\PublicGuildEventsQuery;

final readonly class DoctrineModuleWidgetDataSource implements ModuleWidgetDataSource
{
    public function __construct(
        private VideoRepository $videos,
        private GuildRepository $guilds,
        private PublicGuildEventsQuery $events,
        private GameReleaseRepository $releases,
        private PublicGameGuideWidgetQuery $guides,
        private PublicDownloadCatalogueQuery $downloads,
        private ForumWorkflowGateway $forum,
        private PublicCompetitionBracketRepository $competitions,
        private CompetitionStandingsQuery $standings,
        private ModuleWidgetServerStatusQuery $serverStatuses,
        private ModuleWidgetGuildPresenceQuery $presence,
    ) {
    }

    public function load(ModuleWidgetDefinition $definition, ModuleWidgetViewer $viewer, int $limit): ModuleWidgetPayload
    {
        $limit = max(1, min(ModuleWidgetPayload::MAX_ITEMS, $limit));

        return match ($definition->source) {
            'guides' => $this->guides($limit),
            'videos' => $this->videos($limit),
            'guilds' => $this->guilds($limit),
            'releases' => $this->releases($limit),
            'events' => $this->events($viewer, $limit),
            'forum' => $this->forum($limit),
            'downloads' => $this->downloads($limit),
            'rankings' => $this->rankings($limit),
            'server-status' => $this->serverStatuses->latest(new \DateTimeImmutable(), $limit),
            'presence' => $this->presence->forViewer($viewer, new \DateTimeImmutable(), $limit),
            default => ModuleWidgetPayload::unavailable('unknown_source'),
        };
    }

    private function guides(int $limit): ModuleWidgetPayload
    {
        $items = [];
        foreach ($this->guides->latest($limit) as $guide) {
            $id = $guide['id'];
            if ($id < 1) {
                continue;
            }
            $items[] = [
                'title' => $this->bounded($guide['title']),
                'summary' => $this->bounded($guide['game_name'].' · '.$guide['game_version'].' · '.$guide['season']),
                'eyebrow' => $this->bounded(str_replace('_', ' ', ucfirst($guide['guide_type']))),
                'href' => '/gaming/guides/'.$id,
            ];
        }

        return $this->collection($items);
    }

    private function videos(int $limit): ModuleWidgetPayload
    {
        $rows = $this->videos->createQueryBuilder('video')
            ->andWhere('video.enabled = true')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('video.featured', 'DESC')
            ->addOrderBy('video.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($rows as $video) {
            if (!$video instanceof Video) {
                continue;
            }
            $duration = $video->getDurationLabel();
            $items[] = [
                'title' => $this->bounded($video->getTitle()),
                'summary' => $this->bounded($video->getDescription()),
                'eyebrow' => $this->bounded('Video'.($duration !== null ? ' · '.$duration : '')),
                'href' => '/videos/'.$video->getSlug(),
            ];
        }

        return $this->collection($items);
    }

    private function guilds(int $limit): ModuleWidgetPayload
    {
        $rows = $this->guilds->createQueryBuilder('guild')
            ->addSelect('game')
            ->join('guild.game', 'game')
            ->andWhere('guild.enabled = true')
            ->andWhere('game.enabled = true')
            ->orderBy('game.name', 'ASC')
            ->addOrderBy('guild.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($rows as $guild) {
            $guildId = $guild->getId();
            if ($guildId === null) {
                continue;
            }
            $game = $guild->getGame();
            $items[] = [
                'title' => $this->bounded($guild->getName()),
                'summary' => $this->bounded($guild->getServerName()),
                'eyebrow' => $this->bounded($game?->getName() ?? 'Gilde'),
                'badge' => $guild->isRecruitmentOpen() ? 'Sucht Mitglieder' : '',
                'href' => '/gaming/guild/'.$guild->getSlug(),
            ];
        }

        return $this->collection($items);
    }

    private function releases(int $limit): ModuleWidgetPayload
    {
        $from = new \DateTimeImmutable();
        $to = $from->modify('+90 days');
        $rows = $this->releases->createQueryBuilder('release')
            ->addSelect('entry', 'game', 'platform', 'edition')
            ->join('release.entry', 'entry')
            ->join('entry.game', 'game')
            ->join('release.platform', 'platform')
            ->leftJoin('release.edition', 'edition')
            ->andWhere('entry.enabled = true')
            ->andWhere('game.enabled = true')
            ->andWhere('release.releaseAt >= :from')
            ->andWhere('release.releaseAt <= :to')
            ->andWhere('release.status != :cancelled')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('cancelled', 'cancelled')
            ->orderBy('release.releaseAt', 'ASC')
            ->addOrderBy('release.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($rows as $release) {
            $game = $release->getEntry()->getGame();
            $items[] = [
                'title' => $this->bounded($game->getName()),
                'summary' => $this->bounded($release->getEdition()?->getName() ?? 'Neue Veröffentlichung'),
                'eyebrow' => $this->bounded($release->getPlatform()->getName().' · '.$release->getRegion()),
                'date' => $release->getReleaseAt()->format('Y-m-d'),
                'href' => '/games/'.$game->getSlug(),
            ];
        }

        return $this->collection($items);
    }

    private function events(ModuleWidgetViewer $viewer, int $limit): ModuleWidgetPayload
    {
        $guildIds = $viewer->guildIds();
        if (!$viewer->authenticated || $guildIds === []) {
            return ModuleWidgetPayload::noItems('guild_membership_required');
        }

        $items = [];
        foreach ($this->events->upcoming(new \DateTimeImmutable(), min(12, $limit)) as $event) {
            $guild = $event->getGuild();
            $guildId = $guild?->getId();
            if ($guild === null || $guildId === null || !$viewer->canAccess('guild', $guildId)) {
                continue;
            }
            $items[] = [
                'title' => $this->bounded($event->getTitle()),
                'summary' => $this->bounded($event->getLocation() ?? $guild->getName()),
                'eyebrow' => $this->bounded($guild->getName()),
                'date' => $event->getStartsAt()->format('Y-m-d\\TH:i:sP'),
                'href' => '/gaming/guild/'.$guild->getSlug(),
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        return $this->collection($items);
    }

    private function forum(int $limit): ModuleWidgetPayload
    {
        $items = [];
        foreach ($this->forum->visibleRooms(null, [], false, true, $limit, 0) as $room) {
            $roomId = filter_var($room['id'] ?? null, FILTER_VALIDATE_INT);
            if ($roomId === false || $roomId < 1 || !is_string($room['title'] ?? null)) {
                continue;
            }
            $items[] = [
                'title' => $this->bounded($room['title']),
                'summary' => (int) ($room['thread_count'] ?? 0).' Themen',
                'eyebrow' => 'Öffentliches Forum',
                'href' => '/forum/rooms/'.$roomId,
            ];
        }

        return $this->collection($items);
    }

    private function downloads(int $limit): ModuleWidgetPayload
    {
        $items = [];
        foreach ($this->downloads->findPublicPackages($limit) as $package) {
            $items[] = [
                'title' => $this->bounded($package->getTitle()),
                'summary' => $this->bounded(ucfirst($package->getType())),
                'eyebrow' => 'Download',
                'href' => '/downloads/'.$package->getSlug(),
            ];
        }

        return $this->collection($items);
    }

    private function rankings(int $limit): ModuleWidgetPayload
    {
        $items = [];
        foreach ($this->competitions->publicCompetitionsWithMatches() as $competition) {
            if ($competition->getId() === null) {
                continue;
            }
            $board = $this->standings->forCompetition($competition);
            if ($board['results'] === [] || $board['standings'] === []) {
                continue;
            }

            $leaders = [];
            foreach (array_slice($board['standings'], 0, 3) as $standing) {
                $participant = $standing['participant'];
                $leaders[] = $this->bounded($participant->getName().' · '.$standing['points'].' pkt');
            }
            $game = $competition->getGame();
            $items[] = [
                'title' => $this->bounded($competition->getName()),
                'summary' => $this->bounded(implode(' · ', $leaders)),
                'eyebrow' => $this->bounded($game?->getName() ?? 'Competition'),
                'href' => '/competitions/'.$competition->getId().'/leaderboard',
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        return $this->collection($items);
    }

    private function bounded(?string $value): string
    {
        $value = trim($value ?? '');
        if (strlen($value) <= ModuleWidgetPayload::MAX_STRING_BYTES) {
            return $value;
        }

        return mb_strcut($value, 0, ModuleWidgetPayload::MAX_STRING_BYTES, 'UTF-8');
    }

    /**
     * @param list<array<string, string|int|bool|null>> $items
     */
    private function collection(array $items): ModuleWidgetPayload
    {
        return $items === [] ? ModuleWidgetPayload::noItems() : ModuleWidgetPayload::ready($items);
    }
}

<?php

declare(strict_types=1);

namespace App\Widget\Module;

use App\Entity\ContentEntry;
use App\Entity\GuildEvent;
use App\Entity\Video;
use App\Repository\ContentEntryRepository;
use App\Repository\GameCatalogue\GameReleaseRepository;
use App\Repository\GuildEventRepository;
use App\Repository\GuildRepository;
use App\Repository\VideoRepository;

final readonly class DoctrineModuleWidgetDataSource implements ModuleWidgetDataSource
{
    public function __construct(
        private ContentEntryRepository $content,
        private VideoRepository $videos,
        private GuildRepository $guilds,
        private GuildEventRepository $events,
        private GameReleaseRepository $releases,
    ) {
    }

    public function load(ModuleWidgetDefinition $definition, ModuleWidgetViewer $viewer, int $limit): ModuleWidgetPayload
    {
        return match ($definition->source) {
            'guides' => $this->guides($limit),
            'videos' => $this->videos($limit),
            'guilds' => $this->guilds($limit),
            'releases' => $this->releases($limit),
            'events' => $this->events($viewer, $limit),
            'server-status', 'forum', 'downloads', 'rankings', 'presence' => ModuleWidgetPayload::unavailable('worker_source_unavailable'),
            default => ModuleWidgetPayload::unavailable('unknown_source'),
        };
    }

    private function guides(int $limit): ModuleWidgetPayload
    {
        $items = [];
        foreach ($this->content->findPublishedAll(min(48, max(12, $limit * 4))) as $entry) {
            if ($entry->getType() !== ContentEntry::TYPE_PAGE) {
                continue;
            }
            $items[] = [
                'title' => $entry->getTitle(),
                'summary' => $entry->getExcerpt() ?? '',
                'eyebrow' => 'Guide',
                'href' => '/page/'.$entry->getSlug(),
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        return $this->collection($items);
    }

    private function videos(int $limit): ModuleWidgetPayload
    {
        $items = [];
        foreach (array_slice($this->videos->findPublished(), 0, $limit) as $video) {
            $items[] = [
                'title' => $video->getTitle(),
                'summary' => $video->getDescription(),
                'eyebrow' => trim('Video'.($video->getDurationLabel() !== null ? ' · '.$video->getDurationLabel() : '')),
                'href' => '/videos/'.$video->getSlug(),
            ];
        }

        return $this->collection($items);
    }

    private function guilds(int $limit): ModuleWidgetPayload
    {
        $items = [];
        foreach (array_slice($this->guilds->findPublicGuilds(), 0, $limit) as $guild) {
            if ($guild->getId() === null) {
                continue;
            }
            $game = $guild->getGame();
            $items[] = [
                'title' => $guild->getName(),
                'summary' => $guild->getServerName(),
                'eyebrow' => $game?->getName() ?? 'Gilde',
                'badge' => $guild->isRecruitmentOpen() ? 'Sucht Mitglieder' : '',
                'href' => '/guild-area/'.$guild->getId(),
            ];
        }

        return $this->collection($items);
    }

    private function releases(int $limit): ModuleWidgetPayload
    {
        $from = new \DateTimeImmutable();
        $to = $from->modify('+90 days');
        $items = [];
        foreach (array_slice($this->releases->upcoming($from, $to), 0, $limit) as $release) {
            $game = $release->getEntry()->getGame();
            $items[] = [
                'title' => $game->getName(),
                'summary' => $release->getEdition()?->getName() ?? 'Neue Veröffentlichung',
                'eyebrow' => $release->getPlatform()->getName().' · '.$release->getRegion(),
                'date' => $release->getReleaseAt()->format('Y-m-d'),
                'href' => '/games/'.$game->getSlug(),
            ];
        }

        return $this->collection($items);
    }

    private function events(ModuleWidgetViewer $viewer, int $limit): ModuleWidgetPayload
    {
        if (!$viewer->authenticated || $viewer->guildIds() === []) {
            return ModuleWidgetPayload::noItems('guild_membership_required');
        }

        $query = $this->events->createQueryBuilder('event')
            ->addSelect('guild', 'game')
            ->join('event.guild', 'guild')
            ->join('guild.game', 'game')
            ->andWhere('event.status = :status')
            ->andWhere('event.startsAt >= :now')
            ->andWhere('guild.enabled = true')
            ->andWhere('game.enabled = true')
            ->setParameter('status', GuildEvent::STATUS_PLANNED)
            ->setParameter('now', new \DateTimeImmutable('-2 hours'))
            ->orderBy('event.startsAt', 'ASC')
            ->setMaxResults(min(48, max(12, $limit * 4)));

        $items = [];
        foreach ($query->getQuery()->getResult() as $event) {
            if (!$event instanceof GuildEvent) {
                continue;
            }
            $guild = $event->getGuild();
            $guildId = $guild?->getId();
            if ($guild === null || $guildId === null || !$viewer->canAccess('guild', $guildId)) {
                continue;
            }
            $items[] = [
                'title' => $event->getTitle(),
                'summary' => $event->getLocation() ?? $guild->getName(),
                'eyebrow' => $guild->getName(),
                'date' => $event->getStartsAt()->format('Y-m-d\\TH:i:sP'),
                'href' => '/guild-area/'.$guildId,
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        return $this->collection($items);
    }

    /**
     * @param list<array<string, string|int|bool|null>> $items
     */
    private function collection(array $items): ModuleWidgetPayload
    {
        return $items === [] ? ModuleWidgetPayload::noItems() : ModuleWidgetPayload::ready($items);
    }
}

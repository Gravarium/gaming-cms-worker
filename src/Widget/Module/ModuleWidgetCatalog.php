<?php

declare(strict_types=1);

namespace App\Widget\Module;

final class ModuleWidgetCatalog
{
    /** @var array<string, ModuleWidgetDefinition> */
    private array $definitions;

    public function __construct()
    {
        $definitions = [
            new ModuleWidgetDefinition(
                'gaming.events',
                'Kommende Events',
                'gaming',
                'events',
                'widgets/modules/cards.html.twig',
                'authenticated',
                'events',
                ['content', 'sidebar'],
            ),
            new ModuleWidgetDefinition(
                'gaming.server-status',
                'Serverstatus',
                'gaming',
                'server-status',
                'widgets/modules/status.html.twig',
                'public',
                'server-status',
                ['content', 'sidebar'],
            ),
            new ModuleWidgetDefinition(
                'gaming.forum',
                'Forum',
                'gaming',
                'forum',
                'widgets/modules/cards.html.twig',
                'public',
                'forum',
                ['content', 'sidebar'],
            ),
            new ModuleWidgetDefinition(
                'gaming.rankings',
                'Ranglisten',
                'gaming',
                'rankings',
                'widgets/modules/cards.html.twig',
                'authenticated',
                'rankings',
                ['content', 'sidebar'],
            ),
            new ModuleWidgetDefinition(
                'gaming.presence',
                'Gilden-Präsenz',
                'gaming',
                'presence',
                'widgets/modules/cards.html.twig',
                'authenticated',
                'presence',
                ['content', 'sidebar'],
            ),
            new ModuleWidgetDefinition(
                'gaming.guilds',
                'Gilden entdecken',
                'gaming',
                'guilds',
                'widgets/modules/cards.html.twig',
                'public',
                'guilds',
                ['content', 'sidebar'],
            ),
            new ModuleWidgetDefinition(
                'gaming.releases',
                'Spielveröffentlichungen',
                'gaming',
                'releases',
                'widgets/modules/cards.html.twig',
                'public',
                'releases',
                ['content', 'sidebar'],
            ),
            new ModuleWidgetDefinition(
                'content.guides',
                'Guides und Seiten',
                'content',
                'guides',
                'widgets/modules/cards.html.twig',
                'public',
                'guides',
                ['content', 'sidebar'],
            ),
            new ModuleWidgetDefinition(
                'content.downloads',
                'Downloads',
                'content',
                'downloads',
                'widgets/modules/cards.html.twig',
                'public',
                'downloads',
                ['content', 'sidebar'],
            ),
            new ModuleWidgetDefinition(
                'video.latest',
                'Aktuelle Videos',
                'video',
                'videos',
                'widgets/modules/cards.html.twig',
                'public',
                'videos',
                ['content', 'sidebar'],
            ),
        ];

        $this->definitions = [];
        foreach ($definitions as $definition) {
            if (isset($this->definitions[$definition->key])) {
                throw new \LogicException('Duplicate module widget key.');
            }
            $this->definitions[$definition->key] = $definition;
        }
    }

    /** @return list<ModuleWidgetDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function get(string $key): ?ModuleWidgetDefinition
    {
        return $this->definitions[$key] ?? null;
    }
}

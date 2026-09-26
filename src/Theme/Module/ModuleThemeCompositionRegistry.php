<?php

declare(strict_types=1);

namespace App\Theme\Module;

use App\Theme\ThemeRegistry;

final readonly class ModuleThemeCompositionRegistry
{
    public function __construct(private ThemeRegistry $themes)
    {
    }

    public function get(string $theme): ModuleThemeComposition
    {
        if (!$this->themes->has($theme)) {
            return $this->composition('nebula', 'themes/modules/nebula.html.twig', 'editorial');
        }

        return match ($theme) {
            'ember', 'gravarium-fantasy', 'gravarium-fantasy-rebuild', 'gravarium-fantasy-v3', 'gravarium-cyberpunk'
                => $this->composition($theme, 'themes/modules/ember.html.twig', 'split'),
            'ocean', 'gravarium-cinematic'
                => $this->composition($theme, 'themes/modules/ocean.html.twig', 'catalogue'),
            default => $this->composition($theme, 'themes/modules/nebula.html.twig', 'editorial'),
        };
    }

    private function composition(string $theme, string $template, string $structure): ModuleThemeComposition
    {
        return match ($structure) {
            'split' => new ModuleThemeComposition(
                $theme,
                $template,
                $structure,
                [
                    ['label' => 'Live', 'href' => '#live'],
                    ['label' => 'Gilden', 'href' => '#guilds'],
                    ['label' => 'Ranglisten', 'href' => '#rankings'],
                ],
                ['gaming.events', 'gaming.guilds', 'gaming.forum'],
                ['gaming.server-status', 'gaming.presence', 'gaming.rankings'],
            ),
            'catalogue' => new ModuleThemeComposition(
                $theme,
                $template,
                $structure,
                [
                    ['label' => 'Spiele', 'href' => '#games'],
                    ['label' => 'Releases', 'href' => '#releases'],
                    ['label' => 'Downloads', 'href' => '#downloads'],
                ],
                ['gaming.releases', 'content.guides', 'content.downloads'],
                ['gaming.server-status', 'gaming.forum', 'gaming.presence'],
            ),
            default => new ModuleThemeComposition(
                $theme,
                $template,
                $structure,
                [
                    ['label' => 'Übersicht', 'href' => '#overview'],
                    ['label' => 'Guides', 'href' => '#guides'],
                    ['label' => 'Events', 'href' => '#events'],
                ],
                ['content.guides', 'gaming.events', 'gaming.releases'],
                ['gaming.server-status', 'gaming.rankings', 'video.latest'],
            ),
        };
    }
}

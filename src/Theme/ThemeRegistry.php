<?php

declare(strict_types=1);

namespace App\Theme;

final class ThemeRegistry
{
    /** @var array<string, ThemeDefinition> */
    private array $themes;

    public function __construct()
    {
        $definitions = [
            new ThemeDefinition('nebula', 'Nebula', '1.0.0', '^1.0', [
                'surfaceRadius' => '1rem',
                'heroGlow' => 'rgba(124, 92, 255, .17)',
                'fontStack' => 'Inter, ui-sans-serif, system-ui, sans-serif',
            ]),
            new ThemeDefinition('ember', 'Ember', '1.0.0', '^1.0', [
                'surfaceRadius' => '.55rem',
                'heroGlow' => 'rgba(255, 107, 80, .18)',
                'fontStack' => 'Inter, ui-sans-serif, system-ui, sans-serif',
            ]),
            new ThemeDefinition('ocean', 'Ocean', '1.0.0', '^1.0', [
                'surfaceRadius' => '1.4rem',
                'heroGlow' => 'rgba(35, 180, 220, .18)',
                'fontStack' => 'Inter, ui-sans-serif, system-ui, sans-serif',
            ]),
        ];

        foreach ([
            'gravarium-portal'=>'Gravarium Portal',
            'gravarium-fantasy'=>'Gravarium Fantasy',
            'gravarium-fantasy-rebuild'=>'Gravarium Fantasy Rebuild',
            'gravarium-fantasy-v3'=>'Gravarium Fantasy V3',
            'gravarium-cyberpunk'=>'Gravarium Cyberpunk',
            'gravarium-visual'=>'Gravarium Visual',
            'gravarium-cinematic'=>'Gravarium Cinematic',
        ] as $key=>$name) {
            $regions = ['top','header','hero','below-hero','left-sidebar','main','right-sidebar','content-wide-1','content-wide-2','bottom','footer'];
            if ($key === 'gravarium-cinematic') $regions = ['top','header','hero','below-hero','sidebar','content','content-wide-1','content-wide-2','bottom','footer'];
            $definitions[] = new ThemeDefinition($key,$name,'1.0.0','^1.0',[
                'surfaceRadius'=>$key==='gravarium-cyberpunk'?'0.25rem':'0.6rem',
                'heroGlow'=>$key==='gravarium-cyberpunk'?'rgba(60,230,255,.15)':'rgba(213,181,106,.12)',
                'fontStack'=>'Inter, ui-sans-serif, system-ui, sans-serif',
            ], $regions, $key==='gravarium-cinematic'?'content':'main');
        }

        foreach ($definitions as $definition) {
            if (isset($this->themes[$definition->key])) {
                throw new \LogicException('Duplicate theme key.');
            }
            $this->themes[$definition->key] = $definition;
        }
    }

    public function has(string $key): bool { return isset($this->themes[$key]); }

    public function get(string $key): ThemeDefinition
    {
        return $this->themes[$key] ?? $this->themes['nebula'];
    }

    /** @return array<string, string> */
    public function choices(): array
    {
        $choices = [];
        foreach ($this->themes as $theme) {
            $choices[$theme->name.' '.$theme->version] = $theme->key;
        }

        return $choices;
    }
}

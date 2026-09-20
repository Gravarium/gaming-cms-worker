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

        foreach ($definitions as $definition) {
            if (isset($this->themes[$definition->key])) {
                throw new \LogicException('Duplicate theme key.');
            }
            $this->themes[$definition->key] = $definition;
        }
    }

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

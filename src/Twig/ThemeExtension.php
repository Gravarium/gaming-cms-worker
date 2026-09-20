<?php

declare(strict_types=1);

namespace App\Twig;

use App\Theme\ThemeRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ThemeExtension extends AbstractExtension
{
    public function __construct(private readonly ThemeRegistry $themes)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('cms_theme', $this->themes->get(...))];
    }
}

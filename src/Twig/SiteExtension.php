<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\MenuItemRepository;
use App\Repository\SiteSettingsRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SiteExtension extends AbstractExtension
{
    public function __construct(
        private readonly SiteSettingsRepository $settings,
        private readonly MenuItemRepository $menuItems,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_settings', $this->settings->current(...)),
            new TwigFunction('main_navigation', $this->menuItems->activeNavigation(...)),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\MenuItem;
use App\Module\CmsModuleManager;
use App\Repository\MenuItemRepository;
use App\Repository\SiteSettingsRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SiteExtension extends AbstractExtension
{
    public function __construct(
        private readonly SiteSettingsRepository $settings,
        private readonly MenuItemRepository $menuItems,
        private readonly CmsModuleManager $modules,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_settings', $this->settings->current(...)),
            new TwigFunction('main_navigation', $this->mainNavigation(...)),
            new TwigFunction('cms_module_enabled', $this->modules->isEnabled(...)),
        ];
    }

    /** @return list<MenuItem> */
    public function mainNavigation(): array
    {
        $items = $this->menuItems->activeNavigation();
        if ($this->modules->isEnabled('content')) {
            return $items;
        }

        return array_values(array_filter(
            $items,
            static fn (MenuItem $item): bool => $item->getPage() === null,
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ContentEntry;
use App\Entity\MenuItem;
use App\Entity\SiteSettings;
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

    /** @return array{site: SiteSettings} */
    public function getGlobals(): array
    {
        return ['site' => $this->settings->current()];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_settings', $this->settings->current(...)),
            new TwigFunction('main_navigation', $this->mainNavigation(...)),
            new TwigFunction('menu_item_visible', $this->isMainNavigationItemVisible(...)),
            new TwigFunction('cms_module_enabled', $this->modules->isEnabled(...)),
        ];
    }

    /** @return list<MenuItem> */
    public function mainNavigation(): array
    {
        return array_values(array_filter(
            $this->menuItems->activeNavigation(),
            $this->isMainNavigationItemVisible(...),
        ));
    }

    public function isMainNavigationItemVisible(MenuItem $item): bool
    {
        if (!$item->isEnabled()) {
            return false;
        }

        $page = $item->getPage();
        if ($page === null) {
            return $item->getUrl() !== null;
        }

        $scheduledUnpublishAt = $page->getScheduledUnpublishAt();

        return $this->modules->isEnabled('content')
            && $page->getType() === ContentEntry::TYPE_PAGE
            && $page->isPubliclyListed()
            && ($scheduledUnpublishAt === null || $scheduledUnpublishAt > new \DateTimeImmutable());
    }
}

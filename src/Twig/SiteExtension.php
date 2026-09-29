<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ContentEntry;
use App\Entity\MenuItem;
use App\Entity\SiteSettings;
use App\Module\CmsModuleManager;
use App\Repository\MenuItemRepository;
use App\Repository\SiteSettingsRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

final class SiteExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly SiteSettingsRepository $settings,
        private readonly MenuItemRepository $menuItems,
        private readonly CmsModuleManager $modules,
        private readonly RequestStack $requests,
    ) {
    }

    /** @return array{site: SiteSettingsTemplateGlobal} */
    public function getGlobals(): array
    {
        return ['site' => new SiteSettingsTemplateGlobal($this->settings, $this->requests)];
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

/**
 * Request-scoped view of public site settings for templates that use `site`
 * before the base template is evaluated.
 */
final class SiteSettingsTemplateGlobal
{
    public function __construct(
        private readonly SiteSettingsRepository $settings,
        private readonly RequestStack $requests,
    ) {
    }

    public function getSiteName(): string { return $this->current()->getSiteName(); }
    public function getDescription(): ?string { return $this->current()->getDescription(); }
    public function getHomeTitle(): string { return $this->current()->getHomeTitle(); }
    public function getHomeText(): string { return $this->current()->getHomeText(); }
    public function getPrimaryColor(): string { return $this->current()->getPrimaryColor(); }
    public function getColorScheme(): string { return $this->current()->getColorScheme(); }
    public function getLogoPath(): ?string { return $this->current()->getLogoPath(); }
    public function getFaviconPath(): ?string { return $this->current()->getFaviconPath(); }
    public function isGamingEnabled(): bool { return $this->current()->isGamingEnabled(); }
    public function isVideoEnabled(): bool { return $this->current()->isVideoEnabled(); }
    public function getThemeKey(): string { return $this->current()->getThemeKey(); }
    public function getDefaultLocale(): string { return $this->current()->getDefaultLocale(); }

    /** @return list<string> */
    public function getEnabledLocales(): array { return $this->current()->getEnabledLocales(); }

    /** @return array<string, string> */
    public function getEnabledLocaleLabels(): array { return $this->current()->getEnabledLocaleLabels(); }

    private function current(): SiteSettings
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return $this->settings->current();
        }

        $cached = $request->attributes->get('_twig_site_settings_context');
        if ($cached instanceof SiteSettings) {
            return $cached;
        }

        $settings = $this->settings->current();
        $request->attributes->set('_twig_site_settings_context', $settings);

        return $settings;
    }
}


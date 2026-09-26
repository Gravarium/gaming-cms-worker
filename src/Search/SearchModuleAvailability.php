<?php

declare(strict_types=1);

namespace App\Search;

use App\Module\CmsModuleManager;
use App\Repository\SiteSettingsRepository;

class SearchModuleAvailability
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function __construct(
        private readonly CmsModuleManager $modules,
        private readonly SiteSettingsRepository $settings,
    ) {
    }

    public function isEnabled(string $moduleKey): bool
    {
        if (array_key_exists($moduleKey, $this->cache)) {
            return $this->cache[$moduleKey];
        }

        $enabled = match ($moduleKey) {
            'content' => $this->modules->isEnabled('content'),
            'gaming' => $this->modules->isEnabled('gaming') && $this->settings->current()->isGamingEnabled(),
            'video' => $this->modules->isEnabled('video') && $this->settings->current()->isVideoEnabled(),
            default => false,
        };

        return $this->cache[$moduleKey] = $enabled;
    }
}

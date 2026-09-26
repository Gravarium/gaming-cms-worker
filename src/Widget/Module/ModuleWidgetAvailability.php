<?php

declare(strict_types=1);

namespace App\Widget\Module;

use App\Module\CmsModuleManager;

final readonly class ModuleWidgetAvailability
{
    public function __construct(private CmsModuleManager $modules)
    {
    }

    public function isEnabled(string $module): bool
    {
        try {
            return $this->modules->isEnabled($module);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}

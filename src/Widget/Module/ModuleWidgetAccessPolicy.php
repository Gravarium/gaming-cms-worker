<?php

declare(strict_types=1);

namespace App\Widget\Module;

final class ModuleWidgetAccessPolicy
{
    public function allows(ModuleWidgetDefinition $definition, ModuleWidgetViewer $viewer, ?int $resourceGuildId = null): bool
    {
        if ($definition->visibility === 'guild' && $resourceGuildId === null) {
            return false;
        }

        return $viewer->canAccess($definition->visibility, $resourceGuildId);
    }
}

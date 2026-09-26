<?php

declare(strict_types=1);

namespace App\Widget\Module;

interface ModuleWidgetDataSource
{
    public function load(ModuleWidgetDefinition $definition, ModuleWidgetViewer $viewer, int $limit): ModuleWidgetPayload;
}

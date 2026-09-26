<?php

declare(strict_types=1);

namespace App\Widget\Module;

final readonly class ModuleWidgetRegistry
{
    public function __construct(
        private ModuleWidgetCatalog $catalog,
        private ModuleWidgetAvailability $availability,
        private ModuleWidgetAccessPolicy $access,
        private DoctrineModuleWidgetDataSource $source,
    ) {
    }

    public function definition(string $key): ?ModuleWidgetDefinition
    {
        return $this->catalog->get($key);
    }

    /**
     * @param array<string, string|int|bool> $config
     */
    public function render(string $key, ModuleWidgetViewer $viewer, array $config = []): ModuleWidgetPayload
    {
        $definition = $this->definition($key);
        if ($definition === null) {
            return ModuleWidgetPayload::unavailable('unknown_widget');
        }
        if (!$this->availability->isEnabled($definition->module)) {
            return ModuleWidgetPayload::unavailable('module_disabled');
        }
        if (!$this->access->allows($definition, $viewer)) {
            return ModuleWidgetPayload::unavailable('not_authorized');
        }

        $limit = $config['count'] ?? 6;
        if (!is_int($limit) || $limit < 1 || $limit > 12) {
            return ModuleWidgetPayload::unavailable('invalid_config');
        }

        try {
            return $this->source->load($definition, $viewer, $limit);
        } catch (\Throwable) {
            return ModuleWidgetPayload::failed();
        }
    }

    /**
     * @param list<string> $keys
     * @param array<string, string|int|bool> $config
     * @return array<string, array{definition: ModuleWidgetDefinition, payload: ModuleWidgetPayload, config: array<string, string|int|bool>}>
     */
    public function renderAll(ModuleWidgetViewer $viewer, array $keys, array $config = []): array
    {
        $result = [];
        foreach ($keys as $key) {
            $definition = $this->definition($key);
            if ($definition === null) {
                continue;
            }
            $result[$key] = [
                'definition' => $definition,
                'payload' => $this->render($key, $viewer, $config),
                'config' => $config,
            ];
        }

        return $result;
    }
}

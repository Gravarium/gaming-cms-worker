<?php

declare(strict_types=1);

namespace App\Widget\Module;

final readonly class ModuleWidgetRegistry
{
    private const DEFAULT_LIMIT = 6;

    public function __construct(
        private ModuleWidgetCatalog $catalog,
        private ModuleWidgetAvailability $availability,
        private ModuleWidgetAccessPolicy $access,
        private ModuleWidgetDataSource $source,
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

        $normalized = $this->normalizeConfig($config);
        if ($normalized === null) {
            return ModuleWidgetPayload::unavailable('invalid_config');
        }

        try {
            return $this->source->load($definition, $viewer, $normalized['count']);
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
        $normalized = $this->normalizeConfig($config);
        foreach ($keys as $key) {
            $definition = $this->definition($key);
            if ($definition === null) {
                continue;
            }
            $result[$key] = [
                'definition' => $definition,
                'payload' => $this->render($key, $viewer, $config),
                'config' => $normalized ?? [],
            ];
        }

        return $result;
    }

    /**
     * @param array<string, string|int|bool> $config
     * @return array{count:int,display:string}|null
     */
    private function normalizeConfig(array $config): ?array
    {
        if (array_diff(array_keys($config), ['count', 'display']) !== []) {
            return null;
        }
        $count = $config['count'] ?? self::DEFAULT_LIMIT;
        $display = $config['display'] ?? 'grid';
        if (!is_int($count) || $count < 1 || $count > ModuleWidgetPayload::MAX_ITEMS
            || !is_string($display) || !in_array($display, ['grid', 'list'], true)
        ) {
            return null;
        }

        return ['count' => $count, 'display' => $display];
    }
}

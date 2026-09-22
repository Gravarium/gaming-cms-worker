<?php

declare(strict_types=1);
namespace App\Widget;

use App\Module\CmsModuleManager;
use Symfony\Component\HttpFoundation\RequestStack;

final class WidgetRegistry
{
    /** @var array<string, WidgetDefinition> */
    private array $definitions = [];
    /** @var array<string, WidgetProvider> */
    private array $providers = [];
    /** @param iterable<WidgetProvider> $providers */
    public function __construct(iterable $providers, private readonly CmsModuleManager $modules, private readonly ?RequestStack $requests = null)
    {
        foreach ($providers as $provider) foreach ($provider->definitions() as $definition) {
            if (isset($this->definitions[$definition->key])) throw new \LogicException('Duplicate widget key.');
            $this->definitions[$definition->key] = $definition;
            $this->providers[$definition->key] = $provider;
        }
    }
    public function get(string $key): ?WidgetDefinition { return $this->definitions[$key] ?? null; }
    public function available(string $key): bool
    {
        $definition = $this->get($key);
        if ($definition === null) return false;
        $request = $this->requests?->getCurrentRequest();
        $cacheKey = '_cms_widget_module_'.$definition->module;
        $cached = $request?->attributes->get($cacheKey);
        if (is_bool($cached)) return $cached;
        try { $enabled = $this->modules->isEnabled($definition->module); }
        catch (\InvalidArgumentException) { $enabled = false; }
        $request?->attributes->set($cacheKey, $enabled);
        return $enabled;
    }
    /** @return list<WidgetDefinition> */
    public function availableDefinitions(): array { return array_values(array_filter($this->definitions, fn (WidgetDefinition $d): bool => $this->available($d->key))); }
    /** @param array<string, string|int|bool> $config
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array { return $this->available($key) ? $this->providers[$key]->data($key, $config) : []; }
}

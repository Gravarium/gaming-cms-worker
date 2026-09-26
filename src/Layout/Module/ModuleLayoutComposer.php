<?php

declare(strict_types=1);

namespace App\Layout\Module;

use App\Theme\Module\ModuleThemeComposition;
use App\Widget\Module\ModuleWidgetDefinition;
use App\Widget\Module\ModuleWidgetPayload;

/**
 * @phpstan-type RenderedWidget array{
 *     definition: ModuleWidgetDefinition,
 *     payload: ModuleWidgetPayload,
 *     config: array<string, string|int|bool>
 * }
 */
final class ModuleLayoutComposer
{
    /**
     * @param array<string, RenderedWidget> $rendered
     * @return array{
     *     template: string,
     *     composition: ModuleThemeComposition,
     *     regions: array{
     *         content: list<RenderedWidget>,
     *         sidebar: list<RenderedWidget>
     *     }
     * }
     */
    public function compose(ModuleThemeComposition $composition, array $rendered): array
    {
        return [
            'template' => $composition->template,
            'composition' => $composition,
            'regions' => [
                'content' => $this->select($composition->contentWidgets, $rendered),
                'sidebar' => $this->select($composition->sidebarWidgets, $rendered),
            ],
        ];
    }

    /**
     * @param list<string> $keys
     * @param array<string, RenderedWidget> $rendered
     * @return list<RenderedWidget>
     */
    private function select(array $keys, array $rendered): array
    {
        $selected = [];
        foreach ($keys as $key) {
            if (isset($rendered[$key])) {
                $selected[] = $rendered[$key];
            }
        }

        return $selected;
    }
}

<?php

declare(strict_types=1);
namespace App\Widget;

interface WidgetProvider
{
    /** @return list<WidgetDefinition> */
    public function definitions(): array;
    /** @param array<string, string|int|bool> $config
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array;
}

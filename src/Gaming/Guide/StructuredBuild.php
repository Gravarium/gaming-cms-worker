<?php

declare(strict_types=1);

namespace App\Gaming\Guide;

final class StructuredBuild
{
    /** @var array<int, BuildComponent> */
    private array $components = [];

    public function add(BuildComponent $component): void
    {
        if (isset($this->components[$component->position])) {
            throw new \DomainException('Build positions must be unique.');
        }
        $this->components[$component->position] = $component;
        ksort($this->components);
    }

    /** @return list<BuildComponent> */
    public function components(): array
    {
        return array_values($this->components);
    }

    public function exportCode(): string
    {
        $payload = array_map(static fn (BuildComponent $component): array => [
            'type' => $component->type,
            'key' => $component->key,
            'position' => $component->position,
            'alternatives' => $component->alternatives,
        ], $this->components());

        return rtrim(strtr(base64_encode((string) json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    public static function importCode(string $code): self
    {
        if ($code === '' || strlen($code) > 50_000 || !preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
            throw new \InvalidArgumentException('Invalid or oversized build code.');
        }
        $padding = (4 - strlen($code) % 4) % 4;
        $decoded = base64_decode(strtr($code.str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false || strlen($decoded) > 32_000) {
            throw new \InvalidArgumentException('Invalid build encoding.');
        }
        $data = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || count($data) > 200) {
            throw new \InvalidArgumentException('Invalid build payload.');
        }
        $build = new self();
        foreach ($data as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Invalid build component payload.');
            }
            $alternatives = $row['alternatives'] ?? [];
            if (!is_array($alternatives) || !array_is_list($alternatives)) {
                throw new \InvalidArgumentException('Invalid alternatives payload.');
            }
            $build->add(new BuildComponent(
                is_string($row['type'] ?? null) ? $row['type'] : '',
                is_string($row['key'] ?? null) ? $row['key'] : '',
                is_int($row['position'] ?? null) ? $row['position'] : 0,
                array_values(array_filter($alternatives, 'is_string')),
            ));
        }

        return $build;
    }
}

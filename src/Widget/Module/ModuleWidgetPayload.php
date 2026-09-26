<?php

declare(strict_types=1);

namespace App\Widget\Module;

final readonly class ModuleWidgetPayload
{
    public const STATUS_READY = 'ready';
    public const STATUS_EMPTY = 'empty';
    public const STATUS_UNAVAILABLE = 'unavailable';
    public const STATUS_ERROR = 'error';

    /**
     * @var list<array<string, string|int|bool|null>>
     */
    public array $items;

    /**
     * @var array<string, string|int|bool>
     */
    public array $meta;

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $status,
        array $items = [],
        public string $reason = '',
        array $meta = [],
    ) {
        if (!in_array($this->status, [self::STATUS_READY, self::STATUS_EMPTY, self::STATUS_UNAVAILABLE, self::STATUS_ERROR], true)) {
            throw new \InvalidArgumentException('Unknown module widget payload status.');
        }
        if (count($items) > 12) {
            throw new \InvalidArgumentException('Module widget payload is too large.');
        }

        $normalizedItems = [];
        foreach ($items as $item) {
            $normalized = [];
            foreach ($item as $key => $value) {
                if ($value !== null && !is_string($value) && !is_int($value) && !is_bool($value)) {
                    throw new \InvalidArgumentException('Module widget payload must contain scalar values only.');
                }
                $normalized[$key] = $value;
            }
            $normalizedItems[] = $normalized;
        }

        $normalizedMeta = [];
        foreach ($meta as $key => $value) {
            if (!is_string($value) && !is_int($value) && !is_bool($value)) {
                throw new \InvalidArgumentException('Module widget metadata must contain scalar values only.');
            }
            $normalizedMeta[$key] = $value;
        }

        /** @var list<array<string, string|int|bool|null>> $normalizedItems */
        $this->items = $normalizedItems;
        /** @var array<string, string|int|bool> $normalizedMeta */
        $this->meta = $normalizedMeta;
    }

    /**
     * @param list<array<string, string|int|bool|null>> $items
     */
    public static function ready(array $items): self
    {
        return new self(self::STATUS_READY, $items);
    }

    public static function noItems(string $reason = 'no_visible_items'): self
    {
        return new self(self::STATUS_EMPTY, [], $reason);
    }

    public static function unavailable(string $reason = 'unavailable'): self
    {
        return new self(self::STATUS_UNAVAILABLE, [], $reason);
    }

    public static function failed(): self
    {
        return new self(self::STATUS_ERROR, [], 'source_failed');
    }

    public function hasItems(): bool
    {
        return $this->items !== [];
    }
}

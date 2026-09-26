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
     * @param list<array<string, string|int|bool|null>> $items
     * @param array<string, string|int|bool> $meta
     */
    public function __construct(
        public string $status,
        public array $items = [],
        public string $reason = '',
        public array $meta = [],
    ) {
        if (!in_array($this->status, [self::STATUS_READY, self::STATUS_EMPTY, self::STATUS_UNAVAILABLE, self::STATUS_ERROR], true)) {
            throw new \InvalidArgumentException('Unknown module widget payload status.');
        }
        if (count($this->items) > 12) {
            throw new \InvalidArgumentException('Module widget payload is too large.');
        }
        foreach ($this->items as $item) {
            foreach ($item as $value) {
                if ($value !== null && !is_scalar($value)) {
                    throw new \InvalidArgumentException('Module widget payload must contain scalar values only.');
                }
            }
        }
        foreach ($this->meta as $value) {
            if (!is_scalar($value)) {
                throw new \InvalidArgumentException('Module widget metadata must contain scalar values only.');
            }
        }
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

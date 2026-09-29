<?php

declare(strict_types=1);

namespace App\Widget\Module;

final readonly class ModuleWidgetPayload
{
    public const STATUS_READY = 'ready';
    public const STATUS_EMPTY = 'empty';
    public const STATUS_UNAVAILABLE = 'unavailable';
    public const STATUS_ERROR = 'error';
    public const MAX_ITEMS = 12;
    public const MAX_FIELDS_PER_ITEM = 8;
    public const MAX_META_FIELDS = 8;
    public const MAX_STRING_BYTES = 512;

    /**
     * @var list<array<string, string|int|bool|null>>
     */
    public array $items;

    /**
     * @var array<string, string|int|bool>
     */
    public array $meta;

    /**
     * @param array<array-key, mixed> $items
     * @param array<array-key, mixed> $meta
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
        if (strlen($this->reason) > 80 || preg_match('/^[a-z0-9_-]*$/D', $this->reason) !== 1) {
            throw new \InvalidArgumentException('Module widget reason must be a short stable code.');
        }
        if (!array_is_list($items) || count($items) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException('Module widget payload is too large.');
        }
        if (count($meta) > self::MAX_META_FIELDS) {
            throw new \InvalidArgumentException('Module widget metadata is too large.');
        }

        $normalizedItems = [];
        foreach ($items as $item) {
            if (!is_array($item) || count($item) > self::MAX_FIELDS_PER_ITEM) {
                throw new \InvalidArgumentException('Module widget item has too many fields.');
            }
            $normalized = [];
            foreach ($item as $key => $value) {
                if (!is_string($key) || preg_match('/^[a-z][a-z0-9_-]{0,49}$/D', $key) !== 1) {
                    throw new \InvalidArgumentException('Module widget payload keys must be stable identifiers.');
                }
                if ($value !== null && !is_string($value) && !is_int($value) && !is_bool($value)) {
                    throw new \InvalidArgumentException('Module widget payload must contain scalar values only.');
                }
                if (is_string($value) && (strlen($value) > self::MAX_STRING_BYTES || !mb_check_encoding($value, 'UTF-8'))) {
                    throw new \InvalidArgumentException('Module widget strings must be valid UTF-8 and bounded.');
                }
                if ($key === 'href' && $value !== null
                    && (!is_string($value) || preg_match('~^/(?!/)[A-Za-z0-9/_\-.%]*$~D', $value) !== 1)
                ) {
                    throw new \InvalidArgumentException('Module widget links must be same-site paths.');
                }
                $normalized[$key] = $value;
            }
            $normalizedItems[] = $normalized;
        }

        $normalizedMeta = [];
        foreach ($meta as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_-]{0,49}$/D', $key) !== 1) {
                throw new \InvalidArgumentException('Module widget metadata keys must be stable identifiers.');
            }
            if (!is_string($value) && !is_int($value) && !is_bool($value)) {
                throw new \InvalidArgumentException('Module widget metadata must contain scalar values only.');
            }
            if (is_string($value) && (strlen($value) > self::MAX_STRING_BYTES || !mb_check_encoding($value, 'UTF-8'))) {
                throw new \InvalidArgumentException('Module widget metadata strings must be valid UTF-8 and bounded.');
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

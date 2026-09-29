<?php

declare(strict_types=1);

namespace App\Service;

final class AuditContextSanitizer
{
    private const SENSITIVE_KEY = '/(?:^|[_\-.])(?:password|passwd|pwd|passphrase|token|secret|credential|authorization|cookie|webhook|(?:api|private|access|signing|encryption|master|symmetric|client)[_\-.]?key|key[_\-.]?material)(?:$|[_\-.])/i';
    private const MAX_DEPTH = 8;
    private const MAX_ITEMS = 100;
    private const MAX_STRING_LENGTH = 4096;

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function sanitize(array $context): array
    {
        return $this->sanitizeMap($context, 0);
    }

    /** @param array<array-key, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeMap(array $context, int $depth): array
    {
        $sanitized = [];
        $items = 0;

        foreach ($context as $key => $value) {
            if ($items >= self::MAX_ITEMS) {
                break;
            }

            $key = (string) $key;
            if ($this->isSensitiveKey($key)) {
                continue;
            }

            $sanitizedValue = $this->sanitizeValue($value, $depth);
            if (!$sanitizedValue['keep']) {
                continue;
            }

            $sanitized[$key] = $sanitizedValue['value'];
            ++$items;
        }

        return $sanitized;
    }

    /** @return array{keep: bool, value: mixed} */
    private function sanitizeValue(mixed $value, int $depth): array
    {
        if ($value === null || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
            return ['keep' => true, 'value' => $value];
        }

        if (is_string($value)) {
            if (str_contains($value, "\0")) {
                return ['keep' => false, 'value' => null];
            }

            return ['keep' => true, 'value' => $this->boundString($value)];
        }

        if (!is_array($value) || $depth >= self::MAX_DEPTH) {
            return ['keep' => false, 'value' => null];
        }

        if (array_is_list($value)) {
            $sanitized = [];
            foreach ($value as $item) {
                if (count($sanitized) >= self::MAX_ITEMS) {
                    break;
                }

                $sanitizedValue = $this->sanitizeValue($item, $depth + 1);
                if ($sanitizedValue['keep']) {
                    $sanitized[] = $sanitizedValue['value'];
                }
            }

            return ['keep' => true, 'value' => $sanitized];
        }

        /** @var array<array-key, mixed> $value */
        return ['keep' => true, 'value' => $this->sanitizeMap($value, $depth + 1)];
    }

    private function boundString(string $value): string
    {
        if (mb_strlen($value, 'UTF-8') <= self::MAX_STRING_LENGTH) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_STRING_LENGTH, 'UTF-8');
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key) ?? $key;

        return preg_match(self::SENSITIVE_KEY, $normalized) === 1;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

final class AuditContextSanitizer
{
    private const SENSITIVE_KEY = '/(?:^|[_\-.])(password|passphrase|token|secret|credential|authorization|cookie|webhook)(?:$|[_\-.])/i';

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function sanitize(array $context): array
    {
        $sanitized = [];
        foreach ($context as $key => $value) {
            $key = (string) $key;
            if ($this->isSensitiveKey($key)) {
                continue;
            }

            $sanitized[$key] = $this->sanitizeValue($value);
        }

        return $sanitized;
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->sanitizeValue(...), $value);
        }

        /** @var array<string, mixed> $value */
        return $this->sanitize($value);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key) ?? $key;

        return preg_match(self::SENSITIVE_KEY, $normalized) === 1;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

final class AuditContextSanitizer
{
    private const REDACTED = '[REDACTED]';

    /** @param array<string|int, mixed> $context
     *  @return array<string|int, mixed>
     */
    public function sanitize(array $context): array
    {
        return $this->sanitizeArray($context, 0);
    }

    /** @param array<string|int, mixed> $values
     *  @return array<string|int, mixed>
     */
    private function sanitizeArray(array $values, int $depth): array
    {
        if ($depth >= 16) {
            return ['_truncated' => true];
        }

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $values[$key] = self::REDACTED;
                continue;
            }
            if (is_array($value)) {
                $values[$key] = $this->sanitizeArray($value, $depth + 1);
            }
        }

        return $values;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));

        if (str_contains($normalized, 'password') || str_contains($normalized, 'passphrase')) {
            return true;
        }

        return in_array($normalized, [
            'secret', 'clientsecret', 'signingsecret',
            'token', 'accesstoken', 'refreshtoken', 'resettoken', 'verificationtoken',
            'authorization', 'cookie', 'sessioncookie',
            'webhook', 'webhookurl',
            'apikey', 'accesskey', 'privatekey',
            'credential', 'credentials',
        ], true);
    }
}

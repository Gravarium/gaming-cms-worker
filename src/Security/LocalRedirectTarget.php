<?php

declare(strict_types=1);

namespace App\Security;

final class LocalRedirectTarget
{
    public static function normalize(?string $target, ?string $fallback = null): ?string
    {
        return self::safeLocalPath($target) ?? self::safeLocalPath($fallback);
    }

    public static function requireSafe(?string $target): ?string
    {
        if ($target === null || trim($target) === '') {
            return null;
        }

        $normalized = self::normalize($target);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Only safe local redirect paths are allowed.');
        }

        return $normalized;
    }

    private static function safeLocalPath(?string $target): ?string
    {
        $target = trim((string) $target);
        if ($target === ''
            || strlen($target) > 2048
            || !str_starts_with($target, '/')
            || str_starts_with($target, '//')
            || str_contains($target, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $target) === 1
            || preg_match('/%(?![0-9a-f]{2})/i', $target) === 1
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|2f|5c|7f)/i', $target) === 1
        ) {
            return null;
        }

        $parts = parse_url($target);
        if (!is_array($parts)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        return $target;
    }
}

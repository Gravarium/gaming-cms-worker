<?php

declare(strict_types=1);

namespace App\Service;

final class MediaUrlPolicy
{
    public function assertSafeRemote(string $url): void
    {
        $url = trim($url);
        if ($url === '' || mb_strlen($url) > 2048 || preg_match('/[\x00-\x1F\x7F]/u', $url) === 1) {
            throw new \DomainException('Die Medien-URL ist ungültig.');
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || trim((string) $parts['host']) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new \DomainException('Medien-URLs müssen sichere HTTP- oder HTTPS-Adressen ohne Zugangsdaten sein.');
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new \DomainException('Lokale Medien-Adressen sind nicht erlaubt.');
        }

        $ipHost = trim($host, '[]');
        if (filter_var($ipHost, FILTER_VALIDATE_IP) !== false
            && filter_var($ipHost, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            throw new \DomainException('Private oder reservierte IP-Adressen sind für externe Medien nicht erlaubt.');
        }
    }

    public function isSafeRemote(string $url): bool
    {
        try {
            $this->assertSafeRemote($url);

            return true;
        } catch (\DomainException) {
            return false;
        }
    }

    public function isSafePlayback(string $url): bool
    {
        $url = trim($url);
        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '/uploads/media/')
                && !str_contains($url, '..')
                && !str_contains($url, '\\')
                && preg_match('/[\x00-\x1F\x7F]/u', $url) !== 1;
        }

        return $this->isSafeRemote($url);
    }
}

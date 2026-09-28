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
        $ipHost = trim($host, '[]');
        if (!$this->isWellFormedHost($ipHost)) {
            throw new \DomainException('Die Medien-URL besitzt keinen eindeutig gültigen Host.');
        }

        if ($ipHost === 'localhost' || str_ends_with($ipHost, '.localhost')) {
            throw new \DomainException('Lokale Medien-Adressen sind nicht erlaubt.');
        }

        if ($this->isAmbiguousNumericHost($ipHost)) {
            throw new \DomainException('Mehrdeutige numerische Host-Schreibweisen sind nicht erlaubt.');
        }

        if ($this->isPrivateOrReservedAddress($ipHost)) {
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

    private function isWellFormedHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        if (str_contains($host, ':')) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/i', $host) === 1;
    }

    private function isAmbiguousNumericHost(string $host): bool
    {
        if (str_contains($host, ':')) {
            return false;
        }

        if (ctype_digit($host) || preg_match('/\A0x[0-9a-f]+\z/i', $host) === 1) {
            return true;
        }

        $labels = explode('.', $host);
        $allLabelsAreDecimal = true;
        foreach ($labels as $label) {
            if (!ctype_digit($label)) {
                $allLabelsAreDecimal = false;
                break;
            }
        }

        if (count($labels) < 4 && $allLabelsAreDecimal) {
            return true;
        }

        if (preg_match('/\A(?:[0-9]+|0x[0-9a-f]+)(?:\.(?:[0-9]+|0x[0-9a-f]+)){1,3}\z/i', $host) === 1) {
            return !$this->isCanonicalIpv4($host);
        }

        return false;
    }

    private function isCanonicalIpv4(string $host): bool
    {
        $segments = explode('.', $host);
        if (count($segments) !== 4) {
            return false;
        }

        foreach ($segments as $segment) {
            if ($segment === '' || !ctype_digit($segment)) {
                return false;
            }

            if ($segment !== '0' && str_starts_with($segment, '0')) {
                return false;
            }

            if ((int) $segment > 255) {
                return false;
            }
        }

        return true;
    }

    private function isPrivateOrReservedAddress(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($host, FILTER_VALIDATE_IP, $flags) === false) {
            return true;
        }

        $packed = inet_pton($host);
        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        $prefix = substr($packed, 0, 12);
        if ($prefix !== str_repeat("\0", 12) && $prefix !== str_repeat("\0", 10)."\xff\xff") {
            return false;
        }

        $mapped = inet_ntop(substr($packed, 12, 4));

        return is_string($mapped) && filter_var($mapped, FILTER_VALIDATE_IP, $flags) === false;
    }
}

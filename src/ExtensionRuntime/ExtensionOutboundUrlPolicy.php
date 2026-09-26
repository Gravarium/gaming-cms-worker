<?php

declare(strict_types=1);

namespace App\ExtensionRuntime;

use Closure;
use DomainException;

final class ExtensionOutboundUrlPolicy
{
    private const MAX_URL_LENGTH = 2048;
    private const MAX_HOST_LENGTH = 253;
    private const MAX_PATH_LENGTH = 1024;
    private const MAX_DNS_RECORDS = 16;
    private const MAX_PINNED_IPS = 16;
    private const MAX_IP_LENGTH = 45;
    private const MAX_HOST_LABEL_LENGTH = 63;

    private readonly Closure $resolver;

    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? function (string $host): array {
            return $this->resolve($host);
        };
    }

    /** @return array{url:string,host:string,ips:list<string>} */
    public function approve(string $url): array
    {
        if (
            $url === ''
            || strlen($url) > self::MAX_URL_LENGTH
            || !$this->isSafeText($url)
            || preg_match('/\s/u', $url) === 1
        ) {
            throw new DomainException('Extension outbound URL is malformed or too large.');
        }

        $parts = parse_url($url);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'])
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || (array_key_exists('port', $parts) && $parts['port'] !== 443)
        ) {
            throw new DomainException('Extension outbound URL must be a plain HTTPS origin.');
        }

        $host = $this->normalizeHost($parts);
        $path = $parts['path'] ?? '';
        if (!is_string($path) || strlen($path) > self::MAX_PATH_LENGTH || !$this->isSafeText($path)) {
            throw new DomainException('Extension outbound URL path is malformed or too large.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->resolveForHost($host);

        if ($ips === []) {
            throw new DomainException('Extension outbound host cannot be resolved.');
        }

        $pinned = [];
        foreach ($ips as $ip) {
            $this->assertPublicIp($ip);
            if (!in_array($ip, $pinned, true)) {
                $pinned[] = $ip;
            }

            if (count($pinned) > self::MAX_PINNED_IPS) {
                throw new DomainException('Extension outbound host returned too many addresses.');
            }
        }

        return ['url' => $url, 'host' => $host, 'ips' => $pinned];
    }

    /** @param array<string, mixed> $parts */
    private function normalizeHost(array $parts): string
    {
        $rawHost = $parts['host'] ?? null;
        if (!is_string($rawHost) || $rawHost === '' || strlen($rawHost) > self::MAX_HOST_LENGTH || !$this->isSafeText($rawHost)) {
            throw new DomainException('Extension outbound host is malformed or too large.');
        }

        if (preg_match('/\s/u', $rawHost) === 1) {
            throw new DomainException('Extension outbound host is malformed.');
        }

        $host = $rawHost;
        if (str_starts_with($host, '[') || str_ends_with($host, ']')) {
            if (!str_starts_with($host, '[') || !str_ends_with($host, ']')) {
                throw new DomainException('Extension outbound host is malformed.');
            }
            $host = substr($host, 1, -1);
        } elseif (str_contains($host, '[') || str_contains($host, ']')) {
            throw new DomainException('Extension outbound host is malformed.');
        }

        if ($host === '') {
            throw new DomainException('Extension outbound host is empty.');
        }

        $host = strtolower($host);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (strlen($host) > self::MAX_IP_LENGTH) {
                throw new DomainException('Extension outbound host is too large.');
            }

            return $host;
        }

        if (strlen($host) > self::MAX_HOST_LENGTH || !str_contains($host, '.') || str_ends_with($host, '.')) {
            throw new DomainException('Extension outbound host is not a public name.');
        }

        foreach (['localhost', '.localhost', '.local', '.internal', '.test', '.invalid', '.example', '.home.arpa'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, $suffix)) {
                throw new DomainException('Extension outbound host is not public.');
            }
        }

        foreach (explode('.', $host) as $label) {
            if (
                $label === ''
                || strlen($label) > self::MAX_HOST_LABEL_LENGTH
                || preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $label) !== 1
            ) {
                throw new DomainException('Extension outbound host is malformed.');
            }
        }

        return $host;
    }

    /** @return list<string> */
    private function resolveForHost(string $host): array
    {
        try {
            $resolved = ($this->resolver)($host);
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($resolved) || !array_is_list($resolved) || count($resolved) > self::MAX_DNS_RECORDS) {
            return [];
        }

        $ips = [];
        foreach ($resolved as $ip) {
            if (!is_string($ip) || $ip === '' || strlen($ip) > self::MAX_IP_LENGTH) {
                return [];
            }
            $ips[] = $ip;
        }

        return $ips;
    }

    private function assertPublicIp(string $ip): void
    {
        if (
            strlen($ip) > self::MAX_IP_LENGTH
            || filter_var($ip, FILTER_VALIDATE_IP) === false
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            throw new DomainException('Extension outbound host resolves to a protected network.');
        }

        $normalized = strtolower($ip);
        if (in_array($normalized, ['0.0.0.0', '255.255.255.255', '::', '::1', '169.254.169.254'], true)) {
            throw new DomainException('Extension outbound host resolves to a protected network.');
        }

        if (str_starts_with($normalized, '::ffff:')) {
            $mapped = substr($normalized, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                && filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            ) {
                throw new DomainException('Extension outbound host resolves to a protected network.');
            }
        }
    }

    /** @return list<string> */
    protected function resolve(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || count($records) > self::MAX_DNS_RECORDS) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (!array_key_exists($key, $record)) {
                    continue;
                }

                $ip = $record[$key];
                if (!is_string($ip) || $ip === '' || strlen($ip) > self::MAX_IP_LENGTH) {
                    return [];
                }
                $ips[] = $ip;
            }
        }

        return $ips;
    }

    private function isSafeText(string $value): bool
    {
        return preg_match('//u', $value) === 1
            && preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 1;
    }
}

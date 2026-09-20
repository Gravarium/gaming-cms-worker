<?php
declare(strict_types=1);
namespace App\ExtensionRuntime;
final class ExtensionOutboundUrlPolicy
{
    /** @return array{url:string,host:string,ips:list<string>} */
    public function approve(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || isset($parts['port']) && (int) $parts['port'] !== 443
        ) {
            throw new \DomainException('Extension outbound URL must be a plain HTTPS origin.');
        }
        $host = strtolower((string) $parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            throw new \DomainException('Extension outbound host is not public.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
        if ($ips === []) {
            throw new \DomainException('Extension outbound host cannot be resolved.');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                || $ip === '169.254.169.254'
            ) {
                throw new \DomainException('Extension outbound host resolves to a protected network.');
            }
        }
        return ['url' => $url, 'host' => $host, 'ips' => array_values(array_unique($ips))];
    }

    /** @return list<string> */
    protected function resolve(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) { return []; }
        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) { $ips[] = $record['ip']; }
            if (isset($record['ipv6'])) { $ips[] = $record['ipv6']; }
        }
        return $ips;
    }
}

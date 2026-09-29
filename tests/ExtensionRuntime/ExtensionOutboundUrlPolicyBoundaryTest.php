<?php

declare(strict_types=1);

namespace App\Tests\ExtensionRuntime;

use App\ExtensionRuntime\ExtensionOutboundUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionOutboundUrlPolicyBoundaryTest extends TestCase
{
    public function testPublicResolvedHostIsPinnedAndResolverSeesCanonicalHost(): void
    {
        $requestedHost = null;
        $policy = new ExtensionOutboundUrlPolicy(
            static function (string $host) use (&$requestedHost): array {
                $requestedHost = $host;

                return ['93.184.216.34', '2001:4860:4860::8888'];
            },
        );

        $approved = $policy->approve('https://API.Example.net/resource');

        self::assertSame('api.example.net', $requestedHost);
        self::assertSame('api.example.net', $approved['host']);
        self::assertSame(['93.184.216.34', '2001:4860:4860::8888'], $approved['ips']);
        self::assertSame('https://API.Example.net/resource', $approved['url']);
    }

    public function testResolvedDuplicateAddressesAreReturnedOnce(): void
    {
        $policy = new ExtensionOutboundUrlPolicy(
            static fn (string $host): array => ['93.184.216.34', '93.184.216.34'],
        );

        $approved = $policy->approve('https://api.example.net/data');

        self::assertSame(['93.184.216.34'], $approved['ips']);
    }

    public function testPublicLiteralAddressDoesNotInvokeResolver(): void
    {
        $policy = new ExtensionOutboundUrlPolicy(
            static function (string $host): array {
                throw new \RuntimeException('The literal address must not be resolved.');
            },
        );

        $approved = $policy->approve('https://8.8.8.8:443/data');

        self::assertSame('8.8.8.8', $approved['host']);
        self::assertSame(['8.8.8.8'], $approved['ips']);
    }

    public function testPublicLiteralIpv6AddressIsCanonicalizedWithoutBrackets(): void
    {
        $approved = (new ExtensionOutboundUrlPolicy())->approve('https://[2001:4860:4860::8888]/data');

        self::assertSame('2001:4860:4860::8888', $approved['host']);
        self::assertSame(['2001:4860:4860::8888'], $approved['ips']);
    }

    public function testProtectedResolvedAddressIsRejected(): void
    {
        $this->expectException(\DomainException::class);

        (new ExtensionOutboundUrlPolicy(
            static fn (string $host): array => ['169.254.169.254'],
        ))->approve('https://metadata.example.net/data');
    }

    public function testResolverFailureIsFailClosed(): void
    {
        $this->expectException(\DomainException::class);

        (new ExtensionOutboundUrlPolicy(
            static function (string $host): array {
                throw new \RuntimeException('DNS unavailable.');
            },
        ))->approve('https://api.example.net/data');
    }

    public function testResolverOverflowIsFailClosed(): void
    {
        $addresses = [];
        for ($octet = 1; $octet <= 17; ++$octet) {
            $addresses[] = '8.8.8.'.$octet;
        }

        $this->expectException(\DomainException::class);

        (new ExtensionOutboundUrlPolicy(
            static fn (string $host): array => $addresses,
        ))->approve('https://api.example.net/data');
    }

    public function testMalformedResolverEntryIsFailClosed(): void
    {
        $this->expectException(\DomainException::class);

        (new ExtensionOutboundUrlPolicy(
            static fn (string $host): array => ['93.184.216.34', 42],
        ))->approve('https://api.example.net/data');
    }

    #[DataProvider('rejectedUrls')]
    public function testRejectsAmbiguousMalformedAndUnsafeUrls(string $url): void
    {
        $this->expectException(\DomainException::class);

        (new ExtensionOutboundUrlPolicy())->approve($url);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedUrls(): iterable
    {
        yield 'non HTTPS scheme' => ['http://8.8.8.8/data'];
        yield 'userinfo' => ['https://user:pass@8.8.8.8/data'];
        yield 'query' => ['https://8.8.8.8/data?token=secret'];
        yield 'empty query' => ['https://8.8.8.8/data?'];
        yield 'fragment' => ['https://8.8.8.8/data#part'];
        yield 'empty fragment' => ['https://8.8.8.8/data#'];
        yield 'non default port' => ['https://8.8.8.8:8443/data'];
        yield 'invalid port' => ['https://8.8.8.8:0/data'];
        yield 'localhost' => ['https://localhost/data'];
        yield 'local suffix' => ['https://api.local/data'];
        yield 'internal suffix' => ['https://api.internal/data'];
        yield 'reserved example suffix' => ['https://api.example/data'];
        yield 'single label host' => ['https://intranet/data'];
        yield 'trailing dot host' => ['https://example.com./data'];
        yield 'invalid host label' => ['https://api_host.example.com/data'];
        yield 'oversized host label' => ['https://'.str_repeat('a', 64).'.com/data'];
        yield 'unicode host' => ['https://éxample.com/data'];
        yield 'whitespace in URL' => ['https://8.8.8.8/data with-space'];
        yield 'control byte' => ["https://8.8.8.8/\n"];
        yield 'invalid UTF-8' => ["https://8.8.8.8/\xC3\x28"];
        yield 'oversized path' => ['https://8.8.8.8/'.str_repeat('a', 1025)];
        yield 'oversized URL' => ['https://8.8.8.8/'.str_repeat('a', 2040)];
        yield 'IPv6 loopback' => ['https://[::1]/data'];
    }
}

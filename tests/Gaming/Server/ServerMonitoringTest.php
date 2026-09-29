<?php

declare(strict_types=1);

namespace App\Tests\Gaming\Server;

use App\Gaming\Server\OutageHistory;
use App\Gaming\Server\PollCircuitBreaker;
use App\Gaming\Server\PollRateLimiter;
use App\Gaming\Server\ProtocolAdapterRegistry;
use App\Gaming\Server\PublicServerStatus;
use App\Gaming\Server\ServerTargetPolicy;
use PHPUnit\Framework\TestCase;

final class ServerMonitoringTest extends TestCase
{
    public function testTargetPolicyRejectsPrivateAndUnapprovedTargets(): void
    {
        $policy = new ServerTargetPolicy(['play.example.test']);
        $policy->assertAllowed('play.example.test', 25565, 'minecraft-status');
        $this->expectException(\DomainException::class);
        $policy->assertAllowed('127.0.0.1', 25565, 'minecraft-status');
    }

    public function testUnknownProtocolFailsClosed(): void
    {
        $this->expectException(\DomainException::class);
        (new ServerTargetPolicy(['play.example.test']))->assertAllowed('play.example.test', 22, 'raw-tcp');
    }

    public function testRegistryOnlyExecutesRegisteredApprovedAdapters(): void
    {
        $registry = new ProtocolAdapterRegistry();
        $registry->register('minecraft-status', static fn (string $host, int $port): array => ['host' => $host, 'port' => $port, 'online' => true]);
        self::assertTrue($registry->poll('minecraft-status', 'play.example.test', 25565)['online']);
        $this->expectException(\RuntimeException::class);
        $registry->poll('source-query', 'play.example.test', 27015);
    }

    public function testCircuitBreakerAndRateLimitBoundRepeatedFailures(): void
    {
        $now = new \DateTimeImmutable('2026-09-24T20:00:00Z');
        $breaker = new PollCircuitBreaker(2, 60);
        $breaker->recordFailure($now);
        $breaker->recordFailure($now);
        $this->expectException(\RuntimeException::class);
        $breaker->assertAvailable($now->modify('+30 seconds'));
    }

    public function testRateLimiterRejectsRapidPoll(): void
    {
        $now = new \DateTimeImmutable('2026-09-24T20:00:00Z');
        $limiter = new PollRateLimiter();
        $limiter->claim('server-1', $now);
        $this->expectException(\RuntimeException::class);
        $limiter->claim('server-1', $now->modify('+10 seconds'));
    }

    public function testStatusStalenessAndOutageHistory(): void
    {
        $observed = new \DateTimeImmutable('2026-09-24T20:00:00Z');
        $status = new PublicServerStatus('online', 5, 20, 42, 'arena', '1.2', false, $observed);
        self::assertFalse($status->isStale($observed->modify('+2 minutes')));
        self::assertTrue($status->isStale($observed->modify('+10 minutes')));
        $outages = new OutageHistory();
        $outages->offline($observed);
        $outages->online($observed->modify('+5 minutes'));
        self::assertCount(1, $outages->entries());
    }
}

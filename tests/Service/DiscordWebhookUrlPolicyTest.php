<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DiscordWebhookUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiscordWebhookUrlPolicyTest extends TestCase
{
    public function testAllowsKnownDiscordWebhookHosts(): void
    {
        $policy = new DiscordWebhookUrlPolicy();
        self::assertSame(
            'https://discord.com/api/webhooks/123/token_value',
            $policy->approve('https://discord.com/api/webhooks/123/token_value'),
        );
        self::assertSame(
            'https://canary.discordapp.com/api/webhooks/123/token_value',
            $policy->approve('https://canary.discordapp.com/api/webhooks/123/token_value'),
        );
    }

    #[DataProvider('unsafeUrls')]
    public function testRejectsStoredUrlsOutsideDiscordBoundary(string $url): void
    {
        $this->expectException(\DomainException::class);
        (new DiscordWebhookUrlPolicy())->approve($url);
    }

    public static function unsafeUrls(): iterable
    {
        yield ['http://discord.com/api/webhooks/123/token'];
        yield ['https://discord.com.evil.example/api/webhooks/123/token'];
        yield ['https://user:pass@discord.com/api/webhooks/123/token'];
        yield ['https://discord.com:8443/api/webhooks/123/token'];
        yield ['https://discord.com/api/webhooks/123/token?redirect=https://evil.example'];
        yield ['https://127.0.0.1/api/webhooks/123/token'];
    }
}

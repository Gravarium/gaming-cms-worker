<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DiscordWebhookUrlPolicy;
use PHPUnit\Framework\TestCase;

final class DiscordWebhookUrlPolicyTest extends TestCase
{
    public function testAcceptsKnownDiscordWebhookHosts(): void
    {
        $policy = new DiscordWebhookUrlPolicy();

        foreach ([
            'https://discord.com/api/webhooks/123456/Abc_DEF-123.token',
            'https://canary.discord.com/api/webhooks/1/token',
            'https://ptb.discordapp.com/api/webhooks/987654/token_value',
        ] as $url) {
            $policy->assertAllowed($url);
            self::addToAssertionCount(1);
        }
    }

    public function testRejectsSsrfSchemesHostsCredentialsAndRedirectStyleParameters(): void
    {
        $policy = new DiscordWebhookUrlPolicy();

        foreach ([
            'http://discord.com/api/webhooks/1/token',
            'https://127.0.0.1/api/webhooks/1/token',
            'https://localhost/api/webhooks/1/token',
            'https://discord.com.evil.test/api/webhooks/1/token',
            'https://user:secret@discord.com/api/webhooks/1/token',
            'https://discord.com/api/webhooks/1/token?redirect=https://127.0.0.1',
            'https://discord.com/api/webhooks/1/token#fragment',
        ] as $url) {
            try {
                $policy->assertAllowed($url);
                self::fail('Unsafe Discord webhook accepted: '.$url);
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
}

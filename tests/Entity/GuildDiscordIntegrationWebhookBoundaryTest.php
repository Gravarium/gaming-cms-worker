<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GuildDiscordIntegration;
use PHPUnit\Framework\TestCase;

final class GuildDiscordIntegrationWebhookBoundaryTest extends TestCase
{
    public function testAcceptsExactEightKilobyteAsciiAndUtf8Payloads(): void
    {
        $asciiPayload = str_repeat('A', 8192);
        $integration = (new GuildDiscordIntegration())->setEncryptedWebhookUrl($asciiPayload);
        self::assertSame($asciiPayload, $integration->getEncryptedWebhookUrl());
        self::assertTrue($integration->hasWebhook());

        $multibytePayload = str_repeat('🎮', 2048);
        self::assertSame(8192, strlen($multibytePayload));
        self::assertSame($multibytePayload, (new GuildDiscordIntegration())->setEncryptedWebhookUrl($multibytePayload)->getEncryptedWebhookUrl());
    }

    public function testPreservesEmptyStringBehavior(): void
    {
        $integration = (new GuildDiscordIntegration())->setEncryptedWebhookUrl('');

        self::assertSame('', $integration->getEncryptedWebhookUrl());
        self::assertFalse($integration->hasWebhook());
    }

    public function testRejectedPayloadsPreserveCiphertextAndUpdatedAt(): void
    {
        $integration = (new GuildDiscordIntegration())->setEncryptedWebhookUrl('previous-encrypted-payload');

        foreach ([str_repeat('A', 8193), "\xFFciphertext", "ciphertext\0suffix"] as $candidate) {
            $previousUpdatedAt = $integration->getUpdatedAt();
            $this->assertRejectedWithoutMutation($integration, $candidate, $previousUpdatedAt);
        }
    }

    private function assertRejectedWithoutMutation(
        GuildDiscordIntegration $integration,
        string $candidate,
        \DateTimeImmutable $previousUpdatedAt,
    ): void {
        $previousPayload = $integration->getEncryptedWebhookUrl();

        try {
            $integration->setEncryptedWebhookUrl($candidate);
        } catch (\InvalidArgumentException) {
            self::assertSame($previousPayload, $integration->getEncryptedWebhookUrl());
            self::assertSame($previousUpdatedAt, $integration->getUpdatedAt());

            return;
        }

        self::fail('An invalid or oversized encrypted webhook payload must be rejected.');
    }
}

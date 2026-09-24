<?php
declare(strict_types=1);
namespace App\Tests\ExternalConnector;
use App\Entity\ExternalConnectorTarget;
use App\Entity\Guild;
use App\ExternalConnector\DiscordGuildNotificationConnectorAdapter;
use App\ExternalConnector\ExternalConnectorTargetDefinition;
use App\ExternalConnector\ExternalNotificationMessage;
use App\ExternalConnector\GuildNotificationRecipientResolver;
use App\Service\GuildWebhookSender;
use PHPUnit\Framework\TestCase;
final class DiscordGuildNotificationConnectorAdapterTest extends TestCase
{
    public function testRoutesAGuildRecipientThroughTheExistingEncryptedDiscordSender(): void
    {
        $guild = new Guild();
        $resolver = new class($guild) implements GuildNotificationRecipientResolver {
            public function __construct(private readonly Guild $guild) {}
            public function resolve(string $recipientReference): ?Guild { return $recipientReference === 'guild:12' ? $this->guild : null; }
        };
        $sender = new class implements GuildWebhookSender {
            public ?array $message = null;
            public function notify(Guild $guild, string $type, string $title, string $message): bool { $this->message = [$guild, $type, $title, $message]; return true; }
        };
        $adapter = new DiscordGuildNotificationConnectorAdapter($resolver, $sender);
        $adapter->send($this->target(DiscordGuildNotificationConnectorAdapter::CONFIGURATION_REFERENCE), new ExternalNotificationMessage('guild_event', 'Raid', 'Starts soon', null, 'guild:12'));
        self::assertSame([$guild, 'guild_event', 'Raid', 'Starts soon'], $sender->message);
    }

    public function testDeliveryFailureIsReportedWithoutLeakingProviderDetails(): void
    {
        $guild = new Guild();
        $resolver = new class($guild) implements GuildNotificationRecipientResolver {
            public function __construct(private readonly Guild $guild) {}
            public function resolve(string $recipientReference): ?Guild { return $recipientReference === 'guild:12' ? $this->guild : null; }
        };
        $sender = new class implements GuildWebhookSender {
            public int $attempts = 0;
            public function notify(Guild $guild, string $type, string $title, string $message): bool { ++$this->attempts; return false; }
        };
        $adapter = new DiscordGuildNotificationConnectorAdapter($resolver, $sender);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Discord notification delivery did not succeed.');
        try {
            $adapter->send($this->target(DiscordGuildNotificationConnectorAdapter::CONFIGURATION_REFERENCE), new ExternalNotificationMessage('guild_event', 'Raid', 'Starts soon', null, 'guild:12'));
        } finally {
            self::assertSame(1, $sender->attempts);
        }
    }

    public function testUnknownRecipientFailsClosedBeforeDelivery(): void
    {
        $resolver = new class implements GuildNotificationRecipientResolver { public function resolve(string $recipientReference): ?Guild { return null; } };
        $sender = new class implements GuildWebhookSender {
            public int $attempts = 0;
            public function notify(Guild $guild, string $type, string $title, string $message): bool { ++$this->attempts; return true; }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The notification recipient cannot be resolved.');
        try {
            (new DiscordGuildNotificationConnectorAdapter($resolver, $sender))->send($this->target(DiscordGuildNotificationConnectorAdapter::CONFIGURATION_REFERENCE), new ExternalNotificationMessage('type', 'Title', 'Message', null, 'guild:404'));
        } finally {
            self::assertSame(0, $sender->attempts);
        }
    }

    public function testRefusesAnUnknownConfigurationReference(): void
    {
        $resolver = new class implements GuildNotificationRecipientResolver { public function resolve(string $recipientReference): ?Guild { return new Guild(); } };
        $sender = new class implements GuildWebhookSender { public function notify(Guild $guild, string $type, string $title, string $message): bool { return true; } };
        $this->expectException(\LogicException::class);
        (new DiscordGuildNotificationConnectorAdapter($resolver, $sender))->send($this->target('notification.unknown'), new ExternalNotificationMessage('type', 'Title', 'Message', null, 'guild:1'));
    }
    private function target(string $reference): ExternalConnectorTargetDefinition
    {
        return new ExternalConnectorTargetDefinition(ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS, 'guild-discord', DiscordGuildNotificationConnectorAdapter::PROVIDER_KEY, 'Guild Discord', true, 10, $reference);
    }
}

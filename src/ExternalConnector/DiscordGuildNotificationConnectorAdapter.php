<?php
declare(strict_types=1);
namespace App\ExternalConnector;
use App\Entity\ExternalConnectorTarget;
use App\Service\GuildWebhookSender;
final readonly class DiscordGuildNotificationConnectorAdapter implements ExternalNotificationConnectorAdapter
{
    public const PROVIDER_KEY = 'discord-webhook';
    public const CONFIGURATION_REFERENCE = 'notification.guild-discord';
    public function __construct(private GuildNotificationRecipientResolver $recipients, private GuildWebhookSender $discord) {}
    public function providerKey(): string { return self::PROVIDER_KEY; }
    public function supports(string $capability): bool { return $capability === ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS; }
    public function send(ExternalConnectorTargetDefinition $target, ExternalNotificationMessage $message): void
    {
        if ($target->configurationReference !== self::CONFIGURATION_REFERENCE) { throw new \LogicException('The Discord guild target must use notification.guild-discord.'); }
        $guild = $message->recipientReference === null ? null : $this->recipients->resolve($message->recipientReference);
        if ($guild === null) { throw new \RuntimeException('The notification recipient cannot be resolved.'); }
        if (!$this->discord->notify($guild, $message->type, $message->title, $message->message)) { throw new \RuntimeException('Discord notification delivery did not succeed.'); }
    }
}

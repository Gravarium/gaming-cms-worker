<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Guild;
use App\Repository\GuildDiscordIntegrationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DiscordWebhookNotifier implements GuildWebhookSender
{
    public function __construct(
        private readonly GuildDiscordIntegrationRepository $integrations,
        private readonly SensitiveDataCipher $cipher,
        private readonly DiscordWebhookUrlPolicy $urlPolicy,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    public function notify(Guild $guild, string $type, string $title, string $message): bool
    {
        $integration = $this->integrations->forGuild($guild);
        if ($integration === null || !$integration->isEnabled() || !$integration->hasWebhook()) { return false; }
        if ($type === 'guild_event' && !$integration->isNotifyEvents()) { return false; }
        if ($type === 'guild_announcement' && !$integration->isNotifyAnnouncements()) { return false; }

        try {
            $url = $this->cipher->decrypt($integration->getEncryptedWebhookUrl());
            $this->urlPolicy->assertAllowed($url);
            $content = mb_strimwidth('**'.$title."**\n".$message, 0, 1900, '…');
            $response = $this->httpClient->request('POST', $url, [
                'json' => ['content' => $content, 'allowed_mentions' => ['parse' => []]],
                'timeout' => 4,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) { return true; }
            $this->logger->warning('Discord webhook returned an error status.', ['guild_id' => $guild->getId(), 'status' => $status]);
        } catch (\Throwable $exception) {
            $this->logger->warning('Discord webhook delivery failed.', ['guild_id' => $guild->getId(), 'exception' => $exception::class]);
        }

        return false;
    }

    public function sendTest(Guild $guild): bool
    {
        return $this->notify($guild, 'test', 'Verbindungstest', 'Die Discord-Anbindung für '.$guild->getName().' funktioniert.');
    }
}

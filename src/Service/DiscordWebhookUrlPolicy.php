<?php

declare(strict_types=1);

namespace App\Service;

final class DiscordWebhookUrlPolicy
{
    public function assertAllowed(string $url): void
    {
        $url = trim($url);
        if ($url === '' || mb_strlen($url) > 1000 || preg_match('/[\x00-\x1F\x7F]/u', $url) === 1) {
            throw new \DomainException('Die Discord-Webhook-Adresse ist ungültig.');
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \DomainException('Discord-Webhooks müssen sichere HTTPS-Adressen auf dem Standardport ohne Zugangsdaten, Query oder Fragment sein.');
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (!in_array($host, ['discord.com', 'canary.discord.com', 'ptb.discord.com', 'discordapp.com', 'canary.discordapp.com', 'ptb.discordapp.com'], true)) {
            throw new \DomainException('Die Webhook-Adresse muss zu einem freigegebenen Discord-Host gehören.');
        }

        if (preg_match('#^/api/webhooks/[1-9][0-9]*/[A-Za-z0-9._-]+$#', (string) ($parts['path'] ?? '')) !== 1) {
            throw new \DomainException('Die Discord-Webhook-Adresse besitzt keinen gültigen Webhook-Pfad.');
        }
    }
}

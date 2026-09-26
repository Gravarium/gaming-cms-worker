<?php

declare(strict_types=1);

namespace App\Service;

final class DiscordWebhookUrlPolicy
{
    /**
     * @var list<string>
     */
    private const ALLOWED_HOSTS = [
        'discord.com',
        'canary.discord.com',
        'ptb.discord.com',
        'discordapp.com',
        'canary.discordapp.com',
        'ptb.discordapp.com',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_AUTHORITIES = [
        'discord.com',
        'discord.com:443',
        'canary.discord.com',
        'canary.discord.com:443',
        'ptb.discord.com',
        'ptb.discord.com:443',
        'discordapp.com',
        'discordapp.com:443',
        'canary.discordapp.com',
        'canary.discordapp.com:443',
        'ptb.discordapp.com',
        'ptb.discordapp.com:443',
    ];

    public function assertAllowed(string $url): void
    {
        $url = trim($url);
        if (
            $url === ''
            || mb_strlen($url) > 1000
            || preg_match('/[\x00-\x20\x7F]/', $url) === 1
            || str_contains($url, '\\')
            || str_contains($url, '%')
        ) {
            throw new \DomainException('Die Discord-Webhook-Adresse ist ungültig.');
        }

        $parts = parse_url($url);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \DomainException('Discord-Webhooks müssen sichere HTTPS-Adressen auf dem Standardport ohne Zugangsdaten, Query oder Fragment sein.');
        }

        $host = $parts['host'] ?? null;
        if (!is_string($host) || !in_array(strtolower($host), self::ALLOWED_HOSTS, true)) {
            throw new \DomainException('Die Webhook-Adresse muss zu einem freigegebenen Discord-Host gehören.');
        }

        $authority = null;
        $matches = [];
        if (preg_match('~^https://([^/?#]+)(?:/|$)~i', $url, $matches) === 1 && isset($matches[1]) && is_string($matches[1])) {
            $authority = strtolower($matches[1]);
        }
        if ($authority === null || !in_array($authority, self::ALLOWED_AUTHORITIES, true)) {
            throw new \DomainException('Die Discord-Webhook-Adresse besitzt keine kanonische Host- und Portdarstellung.');
        }

        $path = $parts['path'] ?? null;
        if (!is_string($path) || preg_match('#^/api/webhooks/[1-9][0-9]*/[A-Za-z0-9._-]+$#D', $path) !== 1) {
            throw new \DomainException('Die Discord-Webhook-Adresse besitzt keinen gültigen Webhook-Pfad.');
        }
    }
}

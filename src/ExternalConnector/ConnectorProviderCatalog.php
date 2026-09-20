<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

final class ConnectorProviderCatalog
{
    /** @return array<string, list<array{key: string, name: string, family: string}>> */
    public function grouped(): array
    {
        return [
            ExternalConnectorTarget::CAPABILITY_MAIL => [
                $this->provider('symfony-mailer', 'Standard-Mailversand', 'SMTP'),
                $this->provider('brevo', 'Brevo', 'SMTP/API'),
                $this->provider('mailgun', 'Mailgun', 'API'),
                $this->provider('sendgrid', 'Twilio SendGrid', 'API'),
                $this->provider('postmark', 'Postmark', 'API'),
                $this->provider('amazon-ses', 'Amazon SES', 'API/SMTP'),
            ],
            ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS => [
                $this->provider('discord-webhook', 'Discord', 'Webhook'),
                $this->provider('slack', 'Slack', 'Webhook/API'),
                $this->provider('microsoft-teams', 'Microsoft Teams', 'Webhook/API'),
                $this->provider('telegram', 'Telegram', 'Bot API'),
                $this->provider('matrix', 'Matrix', 'Client API'),
                $this->provider('mattermost', 'Mattermost', 'Webhook/API'),
            ],
            ExternalConnectorTarget::CAPABILITY_IDENTITY => [
                $this->provider('openid-connect', 'OpenID Connect', 'OIDC'),
                $this->provider('keycloak', 'Keycloak', 'OIDC'),
                $this->provider('authentik', 'Authentik', 'OIDC'),
                $this->provider('microsoft-entra', 'Microsoft Entra ID', 'OIDC'),
                $this->provider('google-identity', 'Google Identity', 'OIDC'),
                $this->provider('github-oauth', 'GitHub', 'OAuth 2'),
                $this->provider('steam', 'Steam', 'OpenID'),
            ],
            ExternalConnectorTarget::CAPABILITY_CDN => [
                $this->provider('cloudflare', 'Cloudflare', 'CDN/API'),
                $this->provider('bunny-cdn', 'Bunny CDN', 'CDN/API'),
                $this->provider('amazon-cloudfront', 'Amazon CloudFront', 'CDN/API'),
                $this->provider('fastly', 'Fastly', 'CDN/API'),
                $this->provider('keycdn', 'KeyCDN', 'CDN/API'),
            ],
            ExternalConnectorTarget::CAPABILITY_ANALYTICS => [
                $this->provider('matomo', 'Matomo', 'Analytics API'),
                $this->provider('plausible', 'Plausible', 'Analytics API'),
                $this->provider('umami', 'Umami', 'Analytics API'),
                $this->provider('google-analytics', 'Google Analytics', 'Analytics API'),
                $this->provider('openpanel', 'OpenPanel', 'Analytics API'),
            ],
        ];
    }

    /** @return array<string, array{capability: string, key: string, name: string, family: string}> */
    public function indexed(): array
    {
        $indexed = [];
        foreach ($this->grouped() as $capability => $providers) {
            foreach ($providers as $provider) {
                $indexed[$capability.':'.$provider['key']] = ['capability' => $capability] + $provider;
            }
        }

        return $indexed;
    }

    /** @return array{key: string, name: string, family: string} */
    private function provider(string $key, string $name, string $family): array
    {
        return ['key' => $key, 'name' => $name, 'family' => $family];
    }
}

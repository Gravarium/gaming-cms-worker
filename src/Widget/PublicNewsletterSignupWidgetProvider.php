<?php

declare(strict_types=1);

namespace App\Widget;

final class PublicNewsletterSignupWidgetProvider implements WidgetProvider
{
    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                key: 'notifications.newsletter-signup',
                label: 'Newsletter abonnieren',
                module: 'notifications',
                template: 'widget/public_newsletter_signup.html.twig',
                regions: [],
                multiple: false,
            ),
        ];
    }

    public function data(string $key, array $config): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace App\Newsletter;

final readonly class NewsletterConsentChallenge
{
    public function __construct(
        public int $subscriptionId,
        public string $email,
        public string $token,
    ) {}
}

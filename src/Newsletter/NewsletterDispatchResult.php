<?php

declare(strict_types=1);

namespace App\Newsletter;

final readonly class NewsletterDispatchResult
{
    public function __construct(
        public int $sent,
        public int $failedAttempts,
        public int $suppressed,
        public int $outstanding,
        public string $campaignStatus,
    ) {}
}

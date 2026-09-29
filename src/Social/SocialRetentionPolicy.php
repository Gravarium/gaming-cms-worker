<?php

declare(strict_types=1);

namespace App\Social;

use App\Entity\Social\SocialMessage;

final class SocialRetentionPolicy
{
    public const DEFAULT_MESSAGE_RETENTION_DAYS = 180;
    public const MIN_MESSAGE_RETENTION_DAYS = 30;
    public const MAX_MESSAGE_RETENTION_DAYS = 730;

    public function assertRetentionDays(int $days): void
    {
        if ($days < self::MIN_MESSAGE_RETENTION_DAYS || $days > self::MAX_MESSAGE_RETENTION_DAYS) {
            throw new \InvalidArgumentException('Message retention must be between 30 and 730 days.');
        }
    }

    public function isExpired(SocialMessage $message, int $retentionDays, \DateTimeImmutable $now): bool
    {
        $this->assertRetentionDays($retentionDays);

        return $message->getCreatedAt()->modify(sprintf('+%d days', $retentionDays)) <= $now;
    }
}

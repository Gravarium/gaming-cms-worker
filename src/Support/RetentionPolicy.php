<?php

declare(strict_types=1);

namespace App\Support;

final class RetentionPolicy
{
    public function deletionDue(\DateTimeImmutable $closedAt, \DateTimeImmutable $now, bool $legalHold, int $retentionDays = 365): bool
    {
        if ($retentionDays < 30 || $retentionDays > 3650) throw new \InvalidArgumentException('Retention period outside approved bounds.');
        return !$legalHold && $now >= $closedAt->modify('+'.$retentionDays.' days');
    }
}

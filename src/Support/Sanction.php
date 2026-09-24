<?php

declare(strict_types=1);

namespace App\Support;

final readonly class Sanction
{
    public function __construct(public int $subjectId, public string $type, public string $ruleReference, public string $reason, public \DateTimeImmutable $startsAt, public ?\DateTimeImmutable $expiresAt)
    {
        if ($subjectId < 1 || !in_array($type, ['warning', 'restriction', 'suspension', 'ban'], true) || trim($ruleReference) === '' || trim($reason) === '') {
            throw new \InvalidArgumentException('Sanction subject, rule and reason are required.');
        }
        if ($expiresAt !== null && $expiresAt <= $startsAt) throw new \InvalidArgumentException('Sanction expiry must follow start.');
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return $now >= $this->startsAt && ($this->expiresAt === null || $now < $this->expiresAt);
    }
}

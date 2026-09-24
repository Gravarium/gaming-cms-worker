<?php

declare(strict_types=1);

namespace App\Gaming\Guild;

final class RecruitmentPipeline
{
    public const SUBMITTED = 'submitted';
    public const REVIEW = 'review';
    public const TRIAL = 'trial';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';
    public const EXPIRED = 'expired';

    private string $status = self::SUBMITTED;
    private ?string $decisionReason = null;
    private ?\DateTimeImmutable $decidedAt = null;
    private ?\DateTimeImmutable $expiresAt = null;
    private ?string $appeal = null;

    public function startReview(): void
    {
        if ($this->status !== self::SUBMITTED) {
            throw new \DomainException('Only submitted applications can enter review.');
        }
        $this->status = self::REVIEW;
    }

    public function startTrial(\DateTimeImmutable $expiresAt): void
    {
        if ($this->status !== self::REVIEW || $expiresAt <= new \DateTimeImmutable()) {
            throw new \DomainException('Trial requires review and a future expiry.');
        }
        $this->status = self::TRIAL;
        $this->expiresAt = $expiresAt;
    }

    public function decide(bool $accepted, string $reason, \DateTimeImmutable $at = new \DateTimeImmutable()): void
    {
        $reason = trim($reason);
        if (!in_array($this->status, [self::REVIEW, self::TRIAL], true) || $reason === '') {
            throw new \DomainException('Decision requires active review or trial and a reason.');
        }
        $this->status = $accepted ? self::ACCEPTED : self::REJECTED;
        $this->decisionReason = $reason;
        $this->decidedAt = $at;
    }

    public function expire(\DateTimeImmutable $now): void
    {
        if ($this->status !== self::TRIAL || $this->expiresAt === null || $this->expiresAt > $now) {
            throw new \DomainException('Trial is not due to expire.');
        }
        $this->status = self::EXPIRED;
    }

    public function appeal(string $message): void
    {
        $message = trim($message);
        if (!in_array($this->status, [self::REJECTED, self::EXPIRED], true) || $message === '') {
            throw new \DomainException('Only rejected or expired applications can be appealed.');
        }
        if ($this->appeal !== null) {
            throw new \DomainException('An appeal is already recorded.');
        }
        $this->appeal = $message;
    }

    public function status(): string { return $this->status; }
    public function decisionReason(): ?string { return $this->decisionReason; }
    public function decidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function expiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function appealMessage(): ?string { return $this->appeal; }
}

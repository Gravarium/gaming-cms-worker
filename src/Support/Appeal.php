<?php

declare(strict_types=1);

namespace App\Support;

final class Appeal
{
    private string $state = 'submitted';
    private ?int $reviewerId = null;
    private ?string $decisionReason = null;

    public function __construct(public readonly int $appellantId, public readonly int $sanctionId, public readonly string $grounds)
    {
        if ($appellantId < 1 || $sanctionId < 1 || trim($grounds) === '' || mb_strlen($grounds) > 5000) throw new \InvalidArgumentException('Appeal grounds are required.');
    }

    public function decide(bool $upheld, int $reviewerId, string $reason): void
    {
        if ($this->state !== 'submitted' || $reviewerId < 1 || $reviewerId === $this->appellantId || trim($reason) === '') throw new \DomainException('Independent final appeal review required.');
        $this->state = $upheld ? 'upheld' : 'denied';
        $this->reviewerId = $reviewerId;
        $this->decisionReason = $reason;
    }

    public function state(): string { return $this->state; }
    /** @return array{reviewerId: int|null, reason: string|null} */
    public function evidence(): array { return ['reviewerId' => $this->reviewerId, 'reason' => $this->decisionReason]; }
}

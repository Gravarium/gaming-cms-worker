<?php

declare(strict_types=1);

namespace App\Gaming\Event;

final readonly class SignupDecision
{
    public const CONFIRMED = 'confirmed';
    public const WAITLIST = 'waitlist';
    public const SUBSTITUTE = 'substitute';

    public function __construct(public string $status, public int $position = 0)
    {
        if (!in_array($status, [self::CONFIRMED, self::WAITLIST, self::SUBSTITUTE], true)) {
            throw new \InvalidArgumentException('Unknown signup decision.');
        }
        if ($position < 0) {
            throw new \InvalidArgumentException('Queue position cannot be negative.');
        }
    }
}

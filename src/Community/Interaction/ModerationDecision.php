<?php

declare(strict_types=1);

namespace App\Community\Interaction;

final readonly class ModerationDecision
{
    public const ACTIONS = ['hide', 'restore', 'lock', 'unlock', 'dismiss_report'];

    public function __construct(
        public string $targetKind,
        public int $targetId,
        public int $moderatorUserId,
        public string $action,
        public string $reason,
        public \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        if ($this->targetKind === '' || $this->targetId < 1 || $this->moderatorUserId < 1) {
            throw new \InvalidArgumentException('Invalid moderation decision identity.');
        }
        if (!in_array($this->action, self::ACTIONS, true) || trim($this->reason) === '') {
            throw new \InvalidArgumentException('Moderation action and reason are required.');
        }
    }
}

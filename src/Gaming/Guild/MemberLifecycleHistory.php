<?php

declare(strict_types=1);

namespace App\Gaming\Guild;

final class MemberLifecycleHistory
{
    /** @var list<array{action:string,actor:int,reason:string,at:\DateTimeImmutable}> */
    private array $events = [];

    public function record(string $action, int $actorUserId, string $reason, \DateTimeImmutable $at = new \DateTimeImmutable()): void
    {
        $allowed = ['promote', 'demote', 'warn', 'absence', 'return', 'onboard', 'offboard'];
        $reason = trim($reason);
        if (!in_array($action, $allowed, true) || $actorUserId < 1 || $reason === '') {
            throw new \InvalidArgumentException('Invalid member lifecycle event.');
        }

        $this->events[] = [
            'action' => $action,
            'actor' => $actorUserId,
            'reason' => $reason,
            'at' => $at,
        ];
    }

    /** @return list<array{action:string,actor:int,reason:string,at:\DateTimeImmutable}> */
    public function events(): array
    {
        return $this->events;
    }
}

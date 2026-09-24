<?php

declare(strict_types=1);

namespace App\Support;

final class SupportCase
{
    private string $state = 'open';
    private ?int $assigneeId = null;
    private int $version = 0;
    /** @var list<array{state: string, actorId: int, reason: string, at: \DateTimeImmutable}> */
    private array $history = [];

    public function __construct(public readonly int $reporterId, public readonly string $subject, public readonly \DateTimeImmutable $deadline)
    {
        if ($reporterId < 1 || trim($subject) === '' || mb_strlen($subject) > 180) {
            throw new \InvalidArgumentException('Case reporter and bounded subject are required.');
        }
    }

    public function assign(int $assigneeId, int $actorId, int $expectedVersion, \DateTimeImmutable $at): void
    {
        $this->assertVersion($expectedVersion);
        if ($assigneeId < 1 || $actorId < 1) throw new \DomainException('Valid assignment actors are required.');
        $this->assigneeId = $assigneeId;
        ++$this->version;
        $this->history[] = ['state' => $this->state, 'actorId' => $actorId, 'reason' => 'assignment_changed', 'at' => $at];
    }

    public function transition(string $state, int $actorId, string $reason, int $expectedVersion, \DateTimeImmutable $at): void
    {
        $this->assertVersion($expectedVersion);
        $allowed = ['open' => ['investigating', 'closed'], 'investigating' => ['awaiting_response', 'decided', 'closed'], 'awaiting_response' => ['investigating', 'closed'], 'decided' => ['appealed', 'closed'], 'appealed' => ['decided', 'closed'], 'closed' => []];
        if (!in_array($state, $allowed[$this->state], true) || $actorId < 1 || trim($reason) === '') {
            throw new \DomainException('Invalid audited case transition.');
        }
        $this->state = $state;
        ++$this->version;
        $this->history[] = ['state' => $state, 'actorId' => $actorId, 'reason' => $reason, 'at' => $at];
    }

    private function assertVersion(int $expectedVersion): void
    {
        if ($expectedVersion !== $this->version) throw new \DomainException('Concurrent case modification detected.');
    }

    public function state(): string { return $this->state; }
    public function version(): int { return $this->version; }
    public function assigneeId(): ?int { return $this->assigneeId; }
    public function isOverdue(\DateTimeImmutable $now): bool { return $this->state !== 'closed' && $now > $this->deadline; }
    /** @return list<array{state: string, actorId: int, reason: string, at: \DateTimeImmutable}> */
    public function history(): array { return $this->history; }
}

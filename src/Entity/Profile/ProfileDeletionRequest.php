<?php

declare(strict_types=1);

namespace App\Entity\Profile;

use App\Entity\User;
use App\Repository\Profile\ProfileDeletionRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProfileDeletionRequestRepository::class)]
#[ORM\Table(name: 'profile_deletion_request')]
class ProfileDeletionRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $executeAfter;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, \DateTimeImmutable $now)
    {
        $this->user = $user;
        $this->request($now);
    }

    public function getUser(): User { return $this->user; }
    public function getStatus(): string { return $this->status; }
    public function getRequestedAt(): \DateTimeImmutable { return $this->requestedAt; }
    public function getExecuteAfter(): \DateTimeImmutable { return $this->executeAfter; }
    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }

    public function request(\DateTimeImmutable $now): void
    {
        if ($this->status === self::STATUS_COMPLETED) {
            throw new \DomainException('Completed deletion cannot be requested again.');
        }
        $this->status = self::STATUS_PENDING;
        $this->requestedAt = $now;
        $this->executeAfter = $now->modify('+30 days');
        $this->cancelledAt = null;
        $this->updatedAt = $now;
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \DomainException('Only pending deletion can be cancelled.');
        }
        $this->status = self::STATUS_CANCELLED;
        $this->cancelledAt = $now;
        $this->updatedAt = $now;
    }

    public function isDue(\DateTimeImmutable $now): bool
    {
        return $this->status === self::STATUS_PENDING && $this->executeAfter <= $now;
    }

    public function complete(\DateTimeImmutable $now): void
    {
        if (!$this->isDue($now)) {
            throw new \DomainException('Deletion retention period has not elapsed.');
        }
        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = $now;
        $this->updatedAt = $now;
    }
}

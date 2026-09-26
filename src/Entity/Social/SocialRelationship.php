<?php

declare(strict_types=1);

namespace App\Entity\Social;

use App\Entity\User;
use App\Repository\Social\SocialRelationshipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SocialRelationshipRepository::class)]
#[ORM\Table(name: 'social_relationship')]
#[ORM\UniqueConstraint(name: 'uniq_social_relationship_pair', columns: ['requester_id', 'recipient_id', 'type'])]
#[ORM\Index(name: 'idx_social_relationship_recipient_state', columns: ['recipient_id', 'state'])]
#[ORM\Index(name: 'idx_social_relationship_requester_state', columns: ['requester_id', 'state'])]
class SocialRelationship
{
    public const TYPE_FRIEND = 'friend';
    public const TYPE_FOLLOW = 'follow';
    public const STATE_PENDING = 'pending';
    public const STATE_ACCEPTED = 'accepted';
    public const STATE_REJECTED = 'rejected';
    public const STATE_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $requester;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\Column(length: 12)]
    private string $type;

    #[ORM\Column(length: 12)]
    private string $state = self::STATE_PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    public function __construct(User $requester, User $recipient, string $type)
    {
        if ($requester === $recipient || ($requester->getId() !== null && $requester->getId() === $recipient->getId())) {
            throw new \InvalidArgumentException('A user cannot create a relationship with themselves.');
        }
        if (!in_array($type, [self::TYPE_FRIEND, self::TYPE_FOLLOW], true)) {
            throw new \InvalidArgumentException('Unknown relationship type.');
        }
        $this->requester = $requester;
        $this->recipient = $recipient;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getRequester(): User { return $this->requester; }
    public function getRecipient(): User { return $this->recipient; }
    public function getType(): string { return $this->type; }
    public function getState(): string { return $this->state; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getRespondedAt(): ?\DateTimeImmutable { return $this->respondedAt; }
    public function isPending(): bool { return $this->state === self::STATE_PENDING; }
    public function isAccepted(): bool { return $this->state === self::STATE_ACCEPTED; }

    public function accept(\DateTimeImmutable $at): self
    {
        $this->transition(self::STATE_ACCEPTED, $at);

        return $this;
    }

    public function reject(\DateTimeImmutable $at): self
    {
        $this->transition(self::STATE_REJECTED, $at);

        return $this;
    }

    public function cancel(\DateTimeImmutable $at): self
    {
        $this->transition(self::STATE_CANCELLED, $at);

        return $this;
    }

    public function reopen(\DateTimeImmutable $at): self
    {
        if (!in_array($this->state, [self::STATE_REJECTED, self::STATE_CANCELLED], true)) {
            throw new \DomainException('Only rejected or cancelled relationships can be reopened.');
        }
        $this->state = self::STATE_PENDING;
        $this->respondedAt = null;
        $this->createdAt = $at;

        return $this;
    }

    private function transition(string $state, \DateTimeImmutable $at): void
    {
        if (!$this->isPending()) {
            throw new \DomainException('Only pending relationship requests can change state.');
        }
        $this->state = $state;
        $this->respondedAt = $at;
    }
}

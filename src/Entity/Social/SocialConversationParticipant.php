<?php

declare(strict_types=1);

namespace App\Entity\Social;

use App\Entity\User;
use App\Repository\Social\SocialConversationParticipantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SocialConversationParticipantRepository::class)]
#[ORM\Table(name: 'social_conversation_participant')]
#[ORM\UniqueConstraint(name: 'uniq_social_participant', columns: ['conversation_id', 'user_id'])]
#[ORM\Index(name: 'idx_social_participant_user_status', columns: ['user_id', 'status'])]
class SocialConversationParticipant
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_MEMBER = 'member';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_LEFT = 'left';
    public const STATUS_REMOVED = 'removed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SocialConversation $conversation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 12)]
    private string $role;

    #[ORM\Column(length: 12)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $joinedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $leftAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastReadAt = null;

    public function __construct(SocialConversation $conversation, User $user, string $role = self::ROLE_MEMBER)
    {
        if (!in_array($role, [self::ROLE_OWNER, self::ROLE_MEMBER], true)) {
            throw new \InvalidArgumentException('Unknown conversation participant role.');
        }
        $this->conversation = $conversation;
        $this->user = $user;
        $this->role = $role;
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getConversation(): SocialConversation { return $this->conversation; }
    public function getUser(): User { return $this->user; }
    public function getRole(): string { return $this->role; }
    public function isOwner(): bool { return $this->role === self::ROLE_OWNER; }
    public function getStatus(): string { return $this->status; }
    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
    public function getJoinedAt(): \DateTimeImmutable { return $this->joinedAt; }
    public function getLeftAt(): ?\DateTimeImmutable { return $this->leftAt; }
    public function getLastReadAt(): ?\DateTimeImmutable { return $this->lastReadAt; }

    public function leave(\DateTimeImmutable $at): self
    {
        if (!$this->isActive()) {
            throw new \DomainException('The participant is not active.');
        }
        $this->status = self::STATUS_LEFT;
        $this->leftAt = $at;

        return $this;
    }

    public function remove(\DateTimeImmutable $at): self
    {
        if (!$this->isActive()) {
            throw new \DomainException('The participant is not active.');
        }
        $this->status = self::STATUS_REMOVED;
        $this->leftAt = $at;

        return $this;
    }

    public function markRead(\DateTimeImmutable $at): self
    {
        if (!$this->isActive()) {
            throw new \DomainException('Inactive participants cannot mark messages read.');
        }
        $this->lastReadAt = $at;

        return $this;
    }
}

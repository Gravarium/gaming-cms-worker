<?php

declare(strict_types=1);

namespace App\Entity\Social;

use App\Entity\User;
use App\Repository\Social\SocialMessageRepository;
use App\Social\SocialRateLimitPolicy;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SocialMessageRepository::class)]
#[ORM\Table(name: 'social_message')]
#[ORM\Index(name: 'idx_social_message_conversation_created', columns: ['conversation_id', 'created_at'])]
#[ORM\Index(name: 'idx_social_message_author_created', columns: ['author_id', 'created_at'])]
class SocialMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SocialConversation $conversation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $deletionReason = null;

    public function __construct(SocialConversation $conversation, User $author, string $body)
    {
        $body = (new SocialRateLimitPolicy())->assertMessage($body);
        $conversation->touchMessage(new \DateTimeImmutable());
        $this->conversation = $conversation;
        $this->author = $author;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getConversation(): SocialConversation { return $this->conversation; }
    public function getAuthor(): User { return $this->author; }
    public function getBody(): string { return $this->body; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getEditedAt(): ?\DateTimeImmutable { return $this->editedAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function getDeletionReason(): ?string { return $this->deletionReason; }
    public function isDeleted(): bool { return $this->deletedAt !== null; }

    public function edit(string $body, \DateTimeImmutable $at): self
    {
        if ($this->isDeleted()) {
            throw new \DomainException('Deleted messages cannot be edited.');
        }
        $body = (new SocialRateLimitPolicy())->assertMessage($body);
        $this->body = $body;
        $this->editedAt = $at;

        return $this;
    }

    public function softDelete(User $actor, string $reason, \DateTimeImmutable $at): self
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('A deletion reason is required.');
        }
        if ($this->isDeleted()) {
            throw new \DomainException('Message is already deleted.');
        }
        $this->deletedAt = $at;
        $this->deletionReason = $reason;
        $this->body = '[Message removed]';

        return $this;
    }

    public function redactForRetention(\DateTimeImmutable $at): self
    {
        if (!$this->isDeleted()) {
            $this->deletedAt = $at;
            $this->deletionReason = 'retention';
            $this->body = '[Message removed after retention period]';
        }

        return $this;
    }
}

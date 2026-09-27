<?php

declare(strict_types=1);

namespace App\Entity\Social;

use App\Entity\User;
use App\Repository\Social\SocialConversationRepository;
use App\Social\SocialRetentionPolicy;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SocialConversationRepository::class)]
#[ORM\Table(name: 'social_conversation')]
#[ORM\Index(name: 'idx_social_conversation_activity', columns: ['last_message_at'])]
class SocialConversation
{
    public const TYPE_DIRECT = 'direct';
    public const TYPE_GROUP = 'group';
    public const STATUS_ACTIVE = 'active';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\Column(length: 12)]
    private string $type;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $title;

    #[ORM\Column]
    private int $retentionDays;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastMessageAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        User $createdBy,
        string $type = self::TYPE_DIRECT,
        ?string $title = null,
        int $retentionDays = SocialRetentionPolicy::DEFAULT_MESSAGE_RETENTION_DAYS,
    ) {
        if (!in_array($type, [self::TYPE_DIRECT, self::TYPE_GROUP], true)) {
            throw new \InvalidArgumentException('Unknown conversation type.');
        }
        if ($type === self::TYPE_GROUP && trim((string) $title) === '') {
            throw new \InvalidArgumentException('Group conversations need a title.');
        }
        if ($title !== null && mb_strlen(trim($title)) > 180) {
            throw new \InvalidArgumentException('Conversation title is too long.');
        }

        $retention = new SocialRetentionPolicy();
        $retention->assertRetentionDays($retentionDays);

        $this->createdBy = $createdBy;
        $this->type = $type;
        $this->title = $title === null ? null : trim($title);
        $this->retentionDays = $retentionDays;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedBy(): User { return $this->createdBy; }
    public function getType(): string { return $this->type; }
    public function isDirect(): bool { return $this->type === self::TYPE_DIRECT; }
    public function isGroup(): bool { return $this->type === self::TYPE_GROUP; }
    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): self
    {
        $title = $title === null ? null : trim($title);
        if ($this->isGroup() && ($title === null || $title === '')) {
            throw new \InvalidArgumentException('Group conversations need a title.');
        }
        if ($title !== null && mb_strlen($title) > 180) {
            throw new \InvalidArgumentException('Conversation title is too long.');
        }
        $this->title = $title;
        $this->touch();

        return $this;
    }
    public function getRetentionDays(): int { return $this->retentionDays; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function getLastMessageAt(): ?\DateTimeImmutable { return $this->lastMessageAt; }
    public function isDeleted(): bool { return $this->deletedAt !== null; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }

    public function touchMessage(\DateTimeImmutable $at): self
    {
        if ($this->isDeleted()) {
            throw new \DomainException('Deleted conversations cannot receive messages.');
        }
        $this->lastMessageAt = $at;
        $this->touch($at);

        return $this;
    }

    public function delete(\DateTimeImmutable $at): self
    {
        $this->deletedAt = $at;
        $this->touch($at);

        return $this;
    }

    private function touch(?\DateTimeImmutable $at = null): void
    {
        $now = $at ?? new \DateTimeImmutable();
        $this->updatedAt = $now > $this->updatedAt ? $now : $this->updatedAt->modify('+1 second');
    }
}

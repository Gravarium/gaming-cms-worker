<?php

declare(strict_types=1);

namespace App\Entity\Community;

use App\Community\Interaction\ModerationDecision;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'community_moderation_decision')]
#[ORM\Index(name: 'idx_community_moderation_target', columns: ['target_kind', 'target_id', 'created_at'])]
class CommunityModerationDecision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $targetKind;

    #[ORM\Column]
    private int $targetId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $moderator;

    #[ORM\Column(length: 32)]
    private string $action;

    #[ORM\Column(length: 1000)]
    private string $reason;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $targetKind, int $targetId, User $moderator, string $action, string $reason)
    {
        $reason = trim($reason);
        if ($targetKind === '' || $targetId < 1 || !in_array($action, ModerationDecision::ACTIONS, true) || $reason === '') {
            throw new \InvalidArgumentException('Invalid moderation decision.');
        }
        $this->targetKind = $targetKind;
        $this->targetId = $targetId;
        $this->moderator = $moderator;
        $this->action = $action;
        $this->reason = $reason;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTargetKind(): string { return $this->targetKind; }
    public function getTargetId(): int { return $this->targetId; }
    public function getModerator(): User { return $this->moderator; }
    public function getAction(): string { return $this->action; }
    public function getReason(): string { return $this->reason; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

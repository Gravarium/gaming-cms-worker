<?php

declare(strict_types=1);

namespace App\Entity\Social;

use App\Entity\User;
use App\Repository\Social\SocialModerationDecisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SocialModerationDecisionRepository::class)]
#[ORM\Table(name: 'social_moderation_decision')]
#[ORM\Index(name: 'idx_social_moderation_target', columns: ['target_kind', 'target_id', 'created_at'])]
class SocialModerationDecision
{
    public const ACTION_HIDE = 'hide';
    public const ACTION_DELETE = 'delete';
    public const ACTION_RESTORE = 'restore';
    public const ACTION_RESTRICT = 'restrict';
    public const ACTIONS = [self::ACTION_HIDE, self::ACTION_DELETE, self::ACTION_RESTORE, self::ACTION_RESTRICT];

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
        if ($targetKind === '' || $targetId < 1 || !in_array($action, self::ACTIONS, true) || $reason === '' || mb_strlen($reason) > 1000) {
            throw new \InvalidArgumentException('Invalid social moderation decision.');
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

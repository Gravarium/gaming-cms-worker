<?php

declare(strict_types=1);

namespace App\Entity\GameGuide;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'game_guide_review_audit')]
#[ORM\Index(name: 'IDX_GAME_GUIDE_REVIEW_AUDIT', columns: ['guide_id', 'occurred_at'])]
class GameGuideReviewAudit
{
    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
    }

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GameGuide::class)]
    #[ORM\JoinColumn(name: 'guide_id', nullable: false, onDelete: 'CASCADE')]
    private ?GameGuide $guide = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\Column(length: 16)]
    private string $status = '';

    #[ORM\Column(length: 500)]
    private string $reason = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, name: 'occurred_at')]
    private \DateTimeImmutable $occurredAt;

    public function getId(): ?int { return $this->id; }
}

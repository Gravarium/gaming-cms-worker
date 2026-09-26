<?php

declare(strict_types=1);

namespace App\Entity\Social;

use App\Entity\User;
use App\Repository\Social\SocialBlockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SocialBlockRepository::class)]
#[ORM\Table(name: 'social_block')]
#[ORM\UniqueConstraint(name: 'uniq_social_block_pair', columns: ['blocker_id', 'blocked_id'])]
#[ORM\Index(name: 'idx_social_block_blocked', columns: ['blocked_id', 'lifted_at'])]
class SocialBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $blocker;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $blocked;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $liftedAt = null;

    public function __construct(User $blocker, User $blocked)
    {
        if ($blocker === $blocked || ($blocker->getId() !== null && $blocker->getId() === $blocked->getId())) {
            throw new \InvalidArgumentException('A user cannot block themselves.');
        }
        $this->blocker = $blocker;
        $this->blocked = $blocked;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getBlocker(): User { return $this->blocker; }
    public function getBlocked(): User { return $this->blocked; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLiftedAt(): ?\DateTimeImmutable { return $this->liftedAt; }
    public function isActive(\DateTimeImmutable $at = new \DateTimeImmutable()): bool { return $this->liftedAt === null || $this->liftedAt > $at; }

    public function lift(\DateTimeImmutable $at): self
    {
        $this->liftedAt = $at;

        return $this;
    }

    public function reactivate(\DateTimeImmutable $at): self
    {
        $this->liftedAt = null;
        $this->createdAt = $at;

        return $this;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity\Community;

use App\Community\Interaction\ReactionPolicy;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'community_reaction')]
#[ORM\UniqueConstraint(name: 'uniq_community_reaction_actor', columns: ['comment_id', 'user_id', 'reaction'])]
class CommunityReaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CommunityComment $comment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 24)]
    private string $reaction;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(CommunityComment $comment, User $user, string $reaction)
    {
        if (!in_array($reaction, ReactionPolicy::ALLOWED, true)) {
            throw new \InvalidArgumentException('Unknown reaction type.');
        }
        $this->comment = $comment;
        $this->user = $user;
        $this->reaction = $reaction;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getComment(): CommunityComment { return $this->comment; }
    public function getUser(): User { return $this->user; }
    public function getReaction(): string { return $this->reaction; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

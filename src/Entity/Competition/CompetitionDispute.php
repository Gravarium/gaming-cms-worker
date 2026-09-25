<?php

declare(strict_types=1);

namespace App\Entity\Competition;

use App\Entity\User;
use App\Repository\Competition\CompetitionDisputeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CompetitionDisputeRepository::class)]
#[ORM\Table(name: 'competition_dispute')]
class CompetitionDispute
{
    public const STATUS_OPEN = 'open';
    public const STATUS_UPHELD = 'upheld';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CompetitionMatch::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CompetitionMatch $match = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $openedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::STATUS_OPEN, self::STATUS_UPHELD, self::STATUS_REJECTED])]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $reason = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $decision = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getMatch(): ?CompetitionMatch { return $this->match; }
    public function setMatch(CompetitionMatch $match): self
    {
        if ($this->openedBy !== null && !$match->hasUser($this->openedBy)) { throw new \DomainException('A dispute must be opened by a match participant.'); }
        $this->match = $match;
        return $this;
    }
    public function getOpenedBy(): ?User { return $this->openedBy; }
    public function setOpenedBy(User $openedBy): self
    {
        if ($this->match !== null && !$this->match->hasUser($openedBy)) { throw new \DomainException('A dispute must be opened by a match participant.'); }
        $this->openedBy = $openedBy;
        return $this;
    }
    public function getDecidedBy(): ?User { return $this->decidedBy; }
    public function getStatus(): string { return $this->status; }
    public function getReason(): string { return $this->reason; }
    public function setReason(string $reason): self
    {
        $reason = trim($reason);
        if ($reason === '') { throw new \InvalidArgumentException('A dispute needs a reason.'); }
        $this->reason = $reason;
        return $this;
    }
    public function getDecision(): ?string { return $this->decision; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function isOpen(): bool { return $this->status === self::STATUS_OPEN; }

    public function decide(User $decidedBy, string $status, string $decision): self
    {
        if (!$this->isOpen()) { throw new \DomainException('This dispute has already been decided.'); }
        if ($this->match?->getStatus() !== CompetitionMatch::STATUS_DISPUTED) { throw new \DomainException('Only disputed matches can receive a dispute decision.'); }
        if (!in_array($status, [self::STATUS_UPHELD, self::STATUS_REJECTED], true)) { throw new \InvalidArgumentException('Invalid dispute decision.'); }
        $decision = trim($decision);
        if ($decision === '') { throw new \InvalidArgumentException('A dispute decision needs an explanation.'); }
        $this->status = $status;
        $this->decision = $decision;
        $this->decidedBy = $decidedBy;
        $this->decidedAt = new \DateTimeImmutable();
        return $this;
    }
}

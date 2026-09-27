<?php

declare(strict_types=1);

namespace App\Entity\Social;

use App\Entity\User;
use App\Repository\Social\SocialReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SocialReportRepository::class)]
#[ORM\Table(name: 'social_report')]
#[ORM\Index(name: 'idx_social_report_queue', columns: ['status', 'created_at'])]
class SocialReport
{
    public const STATUS_OPEN = 'open';
    public const STATUS_REVIEWING = 'reviewing';
    public const STATUS_UPHELD = 'upheld';
    public const STATUS_REJECTED = 'rejected';
    public const REASONS = ['spam', 'abuse', 'harassment', 'illegal', 'privacy', 'other'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SocialMessage $message;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $reporter;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $moderator = null;

    #[ORM\Column(length: 32)]
    private string $reason;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $details;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $decisionReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    public function __construct(SocialMessage $message, User $reporter, string $reason, ?string $details = null)
    {
        $details = $details === null ? null : trim($details);
        if (!in_array($reason, self::REASONS, true) || ($details !== null && mb_strlen($details) > 2000)) {
            throw new \InvalidArgumentException('Invalid social report.');
        }
        $this->message = $message;
        $this->reporter = $reporter;
        $this->reason = $reason;
        $this->details = $details === '' ? null : $details;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getMessage(): SocialMessage { return $this->message; }
    public function getReporter(): User { return $this->reporter; }
    public function getModerator(): ?User { return $this->moderator; }
    public function getReason(): string { return $this->reason; }
    public function getDetails(): ?string { return $this->details; }
    public function getStatus(): string { return $this->status; }
    public function getDecisionReason(): ?string { return $this->decisionReason; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }

    public function startReview(): self
    {
        if ($this->status !== self::STATUS_OPEN) {
            throw new \DomainException('Only open reports can enter review.');
        }
        $this->status = self::STATUS_REVIEWING;

        return $this;
    }

    public function decide(bool $upheld, User $moderator, string $reason, \DateTimeImmutable $at): self
    {
        $reason = trim($reason);
        if ($this->status !== self::STATUS_REVIEWING || $reason === '' || mb_strlen($reason) > 1000) {
            throw new \DomainException('A reviewed report needs a decision reason.');
        }
        $this->status = $upheld ? self::STATUS_UPHELD : self::STATUS_REJECTED;
        $this->moderator = $moderator;
        $this->decisionReason = $reason;
        $this->decidedAt = $at;

        return $this;
    }
}

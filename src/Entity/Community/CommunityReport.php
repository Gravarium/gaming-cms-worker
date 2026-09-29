<?php

declare(strict_types=1);

namespace App\Entity\Community;

use App\Community\Interaction\ReportRecord;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'community_report')]
#[ORM\Index(name: 'idx_community_report_queue', columns: ['status', 'created_at'])]
class CommunityReport
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
    private User $reporter;

    #[ORM\Column(length: 32)]
    private string $reason;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $details;

    #[ORM\Column(length: 16)]
    private string $status = ReportRecord::STATUS_OPEN;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $decisionReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    public function __construct(CommunityComment $comment, User $reporter, string $reason, ?string $details = null)
    {
        $details = $details === null ? null : trim($details);
        if (!in_array($reason, ReportRecord::REASONS, true) || ($details !== null && mb_strlen($details) > 2000)) {
            throw new \InvalidArgumentException('Invalid report.');
        }
        $this->comment = $comment;
        $this->reporter = $reporter;
        $this->reason = $reason;
        $this->details = $details === '' ? null : $details;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getStatus(): string { return $this->status; }
    public function getComment(): CommunityComment { return $this->comment; }
    public function getReporter(): User { return $this->reporter; }
    public function getReason(): string { return $this->reason; }
    public function getDetails(): ?string { return $this->details; }
    public function getDecisionReason(): ?string { return $this->decisionReason; }

    public function startReview(): void
    {
        if ($this->status !== ReportRecord::STATUS_OPEN) {
            throw new \DomainException('Only open reports can enter review.');
        }
        $this->status = ReportRecord::STATUS_REVIEWING;
    }

    public function decide(bool $upheld, User $moderator, string $reason): void
    {
        $reason = trim($reason);
        if ($this->status !== ReportRecord::STATUS_REVIEWING || $reason === '') {
            throw new \DomainException('Report decision requires active review and reason.');
        }
        $this->status = $upheld ? ReportRecord::STATUS_RESOLVED : ReportRecord::STATUS_REJECTED;
        $this->decidedBy = $moderator;
        $this->decisionReason = $reason;
        $this->decidedAt = new \DateTimeImmutable();
    }
}

<?php

declare(strict_types=1);

namespace App\Entity\Newsletter;

use App\Entity\User;
use App\Repository\Newsletter\NewsletterCampaignRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: NewsletterCampaignRepository::class)]
#[ORM\Table(name: 'newsletter_campaign')]
class NewsletterCampaign
{
    public const SEGMENT_ALL = 'all';
    public const SEGMENT_MEMBERS = 'members';
    public const SEGMENT_GUESTS = 'guests';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $createdBy = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 160)]
    private string $title = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $subject = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $bodyText = '';

    #[ORM\Column(length: 20)]
    private string $segment = self::SEGMENT_ALL;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(options: ['default' => 500])]
    #[Assert\Range(min: 1, max: 10000)]
    private int $maxRecipients = 500;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(User $user): self { $this->createdBy = $user; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = trim($title); $this->touch(); return $this; }
    public function getSubject(): string { return $this->subject; }
    public function setSubject(string $subject): self { $this->subject = trim($subject); $this->touch(); return $this; }
    public function getBodyText(): string { return $this->bodyText; }
    public function setBodyText(string $body): self { $this->bodyText = trim($body); $this->touch(); return $this; }
    public function getSegment(): string { return $this->segment; }

    public function setSegment(string $segment): self
    {
        if (!in_array($segment, [self::SEGMENT_ALL, self::SEGMENT_MEMBERS, self::SEGMENT_GUESTS], true)) {
            throw new \InvalidArgumentException('Unknown newsletter segment.');
        }
        $this->segment = $segment;
        $this->touch();
        return $this;
    }

    public function getStatus(): string { return $this->status; }
    public function getScheduledAt(): ?\DateTimeImmutable { return $this->scheduledAt; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function getMaxRecipients(): int { return $this->maxRecipients; }

    public function setMaxRecipients(int $limit): self
    {
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('Newsletter recipient limit must be between 1 and 10000.');
        }
        $this->maxRecipients = $limit;
        $this->touch();
        return $this;
    }

    public function schedule(\DateTimeImmutable $at, \DateTimeImmutable $now): void
    {
        $this->assertMutable();
        if ($at <= $now) {
            throw new \DomainException('Newsletter scheduling requires a future time.');
        }
        $this->status = self::STATUS_SCHEDULED;
        $this->scheduledAt = $at;
        $this->touch($now);
    }

    public function canDispatch(\DateTimeImmutable $now): bool
    {
        return $this->status === self::STATUS_DRAFT
            || ($this->status === self::STATUS_SCHEDULED && $this->scheduledAt !== null && $this->scheduledAt <= $now)
            || $this->status === self::STATUS_SENDING;
    }

    public function markSending(\DateTimeImmutable $now): void
    {
        if (!$this->canDispatch($now)) {
            throw new \DomainException('Newsletter campaign is not dispatchable.');
        }
        $this->status = self::STATUS_SENDING;
        $this->touch($now);
    }

    public function markSent(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_SENT;
        $this->sentAt = $now;
        $this->scheduledAt = null;
        $this->touch($now);
    }

    public function markFailed(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_FAILED;
        $this->touch($now);
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        $this->assertMutable();
        $this->status = self::STATUS_CANCELLED;
        $this->scheduledAt = null;
        $this->touch($now);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_CANCELLED], true);
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function assertMutable(): void
    {
        if ($this->isFinal() || $this->status === self::STATUS_SENDING) {
            throw new \DomainException('Final or sending newsletter campaigns are immutable.');
        }
    }

    private function touch(?\DateTimeImmutable $now = null): void
    {
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }
}

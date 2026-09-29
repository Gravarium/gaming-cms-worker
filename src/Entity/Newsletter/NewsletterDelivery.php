<?php

declare(strict_types=1);

namespace App\Entity\Newsletter;

use App\Repository\Newsletter\NewsletterDeliveryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NewsletterDeliveryRepository::class)]
#[ORM\Table(name: 'newsletter_delivery')]
#[ORM\UniqueConstraint(name: 'uniq_newsletter_delivery_pair', columns: ['campaign_id', 'subscription_id'])]
class NewsletterDelivery
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RETRY = 'retry';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SUPPRESSED = 'suppressed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private NewsletterCampaign $campaign;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private NewsletterSubscription $subscription;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $retryAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $lastFailureCode = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(NewsletterCampaign $campaign, NewsletterSubscription $subscription)
    {
        $this->campaign = $campaign;
        $this->subscription = $subscription;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCampaign(): NewsletterCampaign { return $this->campaign; }
    public function getSubscription(): NewsletterSubscription { return $this->subscription; }
    public function getStatus(): string { return $this->status; }
    public function getAttempts(): int { return $this->attempts; }
    public function getRetryAt(): ?\DateTimeImmutable { return $this->retryAt; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function getLastFailureCode(): ?string { return $this->lastFailureCode; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function isDispatchableAt(\DateTimeImmutable $now): bool
    {
        return $this->status === self::STATUS_PENDING
            || ($this->status === self::STATUS_RETRY && $this->retryAt !== null && $this->retryAt <= $now);
    }

    public function markSent(\DateTimeImmutable $now): void
    {
        ++$this->attempts;
        $this->status = self::STATUS_SENT;
        $this->sentAt = $now;
        $this->retryAt = null;
        $this->lastFailureCode = null;
        $this->updatedAt = $now;
    }

    public function markFailure(string $code, \DateTimeImmutable $now): void
    {
        $code = strtolower(trim($code));
        if (preg_match('/^[a-z0-9._-]{1,64}$/D', $code) !== 1) {
            throw new \InvalidArgumentException('Newsletter failure codes must be bounded identifiers.');
        }

        ++$this->attempts;
        $this->lastFailureCode = $code;
        $this->updatedAt = $now;
        if ($this->attempts >= 5) {
            $this->status = self::STATUS_FAILED;
            $this->retryAt = null;
            return;
        }

        $delayMinutes = min(360, 5 * (2 ** ($this->attempts - 1)));
        $this->status = self::STATUS_RETRY;
        $this->retryAt = $now->modify('+'.$delayMinutes.' minutes');
    }

    public function suppress(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_SUPPRESSED;
        $this->retryAt = null;
        $this->updatedAt = $now;
    }
}

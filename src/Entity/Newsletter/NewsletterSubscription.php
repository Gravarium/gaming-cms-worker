<?php

declare(strict_types=1);

namespace App\Entity\Newsletter;

use App\Entity\User;
use App\Repository\Newsletter\NewsletterSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: NewsletterSubscriptionRepository::class)]
#[ORM\Table(name: 'newsletter_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_newsletter_subscription_email', columns: ['email'])]
class NewsletterSubscription
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';
    public const STATUS_SUPPRESSED = 'suppressed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 180)]
    #[Assert\Email]
    private string $email = '';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 80)]
    private string $consentSource = 'account';

    #[ORM\Column(length: 32)]
    private string $consentVersion = 'v1';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $confirmationTokenHash = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmationRequestedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $unsubscribeTokenHash = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $unsubscribedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $suppressedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $suppressionReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; $this->touch(); return $this; }
    public function getEmail(): string { return $this->email; }

    public function setEmail(string $email): self
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 180) {
            throw new \InvalidArgumentException('Invalid newsletter email.');
        }
        $this->email = $email;
        $this->touch();
        return $this;
    }

    public function getStatus(): string { return $this->status; }
    public function getConsentSource(): string { return $this->consentSource; }
    public function getConsentVersion(): string { return $this->consentVersion; }
    public function getConfirmationRequestedAt(): ?\DateTimeImmutable { return $this->confirmationRequestedAt; }
    public function getConfirmedAt(): ?\DateTimeImmutable { return $this->confirmedAt; }
    public function getUnsubscribedAt(): ?\DateTimeImmutable { return $this->unsubscribedAt; }
    public function getSuppressedAt(): ?\DateTimeImmutable { return $this->suppressedAt; }
    public function getSuppressionReason(): ?string { return $this->suppressionReason; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function issueConfirmation(string $source, string $version, \DateTimeImmutable $now): string
    {
        if ($this->status === self::STATUS_SUPPRESSED) {
            throw new \DomainException('Suppressed newsletter addresses cannot be re-subscribed.');
        }

        $source = trim($source);
        $version = trim($version);
        if ($source === '' || mb_strlen($source) > 80 || $version === '' || mb_strlen($version) > 32) {
            throw new \InvalidArgumentException('Invalid newsletter consent evidence.');
        }

        $raw = self::token();
        $this->consentSource = $source;
        $this->consentVersion = $version;
        $this->confirmationTokenHash = hash('sha256', $raw);
        $this->confirmationRequestedAt = $now;
        $this->confirmedAt = null;
        $this->unsubscribedAt = null;
        $this->status = self::STATUS_PENDING;
        $this->touch($now);

        return $raw;
    }

    public function confirm(string $rawToken, \DateTimeImmutable $now): void
    {
        if ($this->status !== self::STATUS_PENDING || $this->confirmationTokenHash === null || !hash_equals($this->confirmationTokenHash, hash('sha256', $rawToken))) {
            throw new \DomainException('Invalid or expired newsletter confirmation.');
        }

        $this->status = self::STATUS_ACTIVE;
        $this->confirmedAt = $now;
        $this->confirmationTokenHash = null;
        $this->touch($now);
    }

    public function issueUnsubscribeToken(\DateTimeImmutable $now): string
    {
        if (!$this->canReceive()) {
            throw new \DomainException('Only active newsletter subscriptions can receive mail.');
        }

        $raw = self::token();
        $this->unsubscribeTokenHash = hash('sha256', $raw);
        $this->touch($now);

        return $raw;
    }

    public function unsubscribe(string $rawToken, \DateTimeImmutable $now): void
    {
        if ($this->unsubscribeTokenHash === null || !hash_equals($this->unsubscribeTokenHash, hash('sha256', $rawToken))) {
            throw new \DomainException('Invalid newsletter unsubscribe token.');
        }

        $this->status = self::STATUS_UNSUBSCRIBED;
        $this->unsubscribedAt = $now;
        $this->unsubscribeTokenHash = null;
        $this->touch($now);
    }

    public function unsubscribeForAccount(\DateTimeImmutable $now): void
    {
        if ($this->status === self::STATUS_SUPPRESSED) {
            return;
        }

        $this->status = self::STATUS_UNSUBSCRIBED;
        $this->unsubscribedAt = $now;
        $this->confirmationTokenHash = null;
        $this->unsubscribeTokenHash = null;
        $this->touch($now);
    }

    public function suppress(string $reason, \DateTimeImmutable $now): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new \InvalidArgumentException('A bounded suppression reason is required.');
        }

        $this->status = self::STATUS_SUPPRESSED;
        $this->suppressedAt = $now;
        $this->suppressionReason = $reason;
        $this->confirmationTokenHash = null;
        $this->unsubscribeTokenHash = null;
        $this->touch($now);
    }

    public function canReceive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->confirmedAt !== null && $this->suppressedAt === null;
    }

    private static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function touch(?\DateTimeImmutable $now = null): void
    {
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }
}

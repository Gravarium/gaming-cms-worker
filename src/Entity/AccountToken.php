<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountTokenRepository::class)]
#[ORM\Table(name: 'account_token')]
#[ORM\Index(name: 'IDX_ACCOUNT_TOKEN_LOOKUP', columns: ['token_hash', 'purpose'])]
class AccountToken
{
    private const MAX_PURPOSE_BYTES = 40;
    private const TOKEN_HASH_BYTES = 64;

    public const PURPOSE_EMAIL_VERIFICATION = 'email_verification';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 40)]
    private string $purpose = '';

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }
    public function getPurpose(): string { return $this->purpose; }
    public function setPurpose(string $purpose): self
    {
        if (
            strlen($purpose) > self::MAX_PURPOSE_BYTES
            || !in_array($purpose, [self::PURPOSE_EMAIL_VERIFICATION, self::PURPOSE_PASSWORD_RESET], true)
        ) {
            throw new \InvalidArgumentException('Unsupported account token purpose.');
        }

        $this->purpose = $purpose;

        return $this;
    }
    public function getTokenHash(): string { return $this->tokenHash; }
    public function setTokenHash(string $tokenHash): self
    {
        if (
            strlen($tokenHash) !== self::TOKEN_HASH_BYTES
            || preg_match('/\A[a-f0-9]{64}\z/D', $tokenHash) !== 1
        ) {
            throw new \InvalidArgumentException('Account token hash must be a lowercase SHA-256 hexadecimal digest.');
        }

        $this->tokenHash = $tokenHash;

        return $this;
    }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): self { $this->expiresAt = $expiresAt; return $this; }
    public function getUsedAt(): ?\DateTimeImmutable { return $this->usedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function isUsable(?\DateTimeImmutable $now = null): bool { return $this->usedAt === null && $this->expiresAt > ($now ?? new \DateTimeImmutable()); }
    public function markUsed(): self { $this->usedAt = new \DateTimeImmutable(); return $this; }
}

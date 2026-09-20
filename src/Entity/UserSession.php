<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserSessionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserSessionRepository::class)]
#[ORM\Table(name: 'user_session')]
#[ORM\UniqueConstraint(name: 'uniq_user_session_hash', columns: ['session_hash'])]
class UserSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'session_hash', length: 64)]
    private string $sessionHash;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column]
    private int $securityVersion;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, string $sessionId, ?string $ipAddress, ?string $userAgent)
    {
        $this->user = $user;
        $this->sessionHash = hash('sha256', $sessionId);
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent === null ? null : mb_substr($userAgent, 0, 255);
        $this->securityVersion = $user->getSecurityVersion();
        $this->createdAt = $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getSessionHash(): string { return $this->sessionHash; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function getSecurityVersion(): int { return $this->securityVersion; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function isRevoked(): bool { return $this->revokedAt !== null; }
    public function touch(?string $ipAddress, ?string $userAgent): void
    {
        $this->lastSeenAt = new \DateTimeImmutable();
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent === null ? null : mb_substr($userAgent, 0, 255);
    }
    public function revoke(): void { $this->revokedAt ??= new \DateTimeImmutable(); }
    public function syncSecurityVersion(): void { $this->securityVersion = $this->user->getSecurityVersion(); }
}

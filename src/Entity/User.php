<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'cms_user')]
#[ORM\UniqueConstraint(name: 'uniq_cms_user_email', columns: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Diese E-Mail-Adresse wird bereits verwendet.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $permissions = [];

    /** @var Collection<int, AccessRole> */
    #[ORM\ManyToMany(targetEntity: AccessRole::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'cms_user_access_role')]
    private Collection $accessRoles;

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $twoFactorEnabled = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $twoFactorSecret = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $twoFactorRecoveryCodes = [];

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $displayName = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $lockReason = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $securityVersion = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); $this->accessRoles = new ArrayCollection(); }
    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self
    {
        $email = mb_strtolower(trim($email));
        if ($this->email !== '' && $this->email !== $email) { $this->emailVerifiedAt = null; }
        $this->email = $email;
        return $this;
    }
    public function isEmailVerified(): bool { return $this->emailVerifiedAt !== null; }
    public function getEmailVerifiedAt(): ?\DateTimeImmutable { return $this->emailVerifiedAt; }
    public function verifyEmail(): self { $this->emailVerifiedAt = new \DateTimeImmutable(); return $this; }
    public function getUserIdentifier(): string { assert($this->email !== ''); return $this->email; }

    /** @return list<string> */
    public function getRoles(): array { $roles = $this->roles; $roles[] = 'ROLE_USER'; return array_values(array_unique($roles)); }
    /** @param list<string> $roles */
    public function setRoles(array $roles): self { $this->roles = array_values(array_unique($roles)); return $this; }
    public function isAdmin(): bool { return in_array('ROLE_ADMIN', $this->roles, true); }
    public function setAdmin(bool $admin): self
    {
        $roles = array_values(array_filter($this->roles, static fn (string $role): bool => $role !== 'ROLE_ADMIN'));
        if ($admin) { $roles[] = 'ROLE_ADMIN'; }
        $this->roles = array_values(array_unique($roles));
        return $this;
    }

    /** @return list<string> */
    public function getPermissions(): array { return $this->permissions; }
    /** @param list<string> $permissions */
    public function setPermissions(array $permissions): self { $this->permissions = array_values(array_unique($permissions)); return $this; }
    public function hasPermission(string $permission): bool { return in_array($permission, $this->getEffectivePermissions(), true); }
    /** @return list<string> */
    public function getEffectivePermissions(): array
    {
        $permissions = $this->permissions;
        foreach ($this->accessRoles as $role) {
            if ($role->isActive()) { $permissions = [...$permissions, ...$role->getPermissions()]; }
        }
        return array_values(array_unique($permissions));
    }
    /** @return Collection<int, AccessRole> */
    public function getAccessRoles(): Collection { return $this->accessRoles; }
    public function addAccessRole(AccessRole $role): self { if (!$this->accessRoles->contains($role)) { $this->accessRoles->add($role); } return $this; }
    public function removeAccessRole(AccessRole $role): self { $this->accessRoles->removeElement($role); return $this; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): self { $this->password = $password; return $this; }
    public function isTwoFactorEnabled(): bool { return $this->twoFactorEnabled; }
    public function getTwoFactorSecret(): ?string { return $this->twoFactorSecret; }
    /** @param list<string> $recoveryCodeHashes */
    public function enableTwoFactor(string $encryptedSecret, array $recoveryCodeHashes): self
    {
        $this->twoFactorSecret = $encryptedSecret;
        $this->twoFactorRecoveryCodes = $recoveryCodeHashes;
        $this->twoFactorEnabled = true;
        $this->invalidateSessions();
        return $this;
    }
    public function disableTwoFactor(): self
    {
        if ($this->twoFactorEnabled || $this->twoFactorSecret !== null || $this->twoFactorRecoveryCodes !== []) {
            $this->invalidateSessions();
        }
        $this->twoFactorEnabled = false;
        $this->twoFactorSecret = null;
        $this->twoFactorRecoveryCodes = [];
        return $this;
    }
    public function consumeRecoveryCode(string $code): bool
    {
        $code = strtoupper(trim($code));
        foreach ($this->twoFactorRecoveryCodes as $index => $hash) {
            if (password_verify($code, $hash)) {
                array_splice($this->twoFactorRecoveryCodes, $index, 1);
                return true;
            }
        }
        return false;
    }
    public function recoveryCodeCount(): int { return count($this->twoFactorRecoveryCodes); }
    public function eraseCredentials(): void {}
    public function getDisplayName(): string { return $this->displayName; }
    public function setDisplayName(string $displayName): self { $this->displayName = trim($displayName); return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
    public function getLockedUntil(): ?\DateTimeImmutable { return $this->lockedUntil; }
    public function setLockedUntil(?\DateTimeImmutable $lockedUntil): self { $this->lockedUntil = $lockedUntil; return $this; }
    public function getLockReason(): ?string { return $this->lockReason; }
    public function setLockReason(?string $reason): self { $reason = $reason === null ? null : trim($reason); $this->lockReason = $reason === '' ? null : $reason; return $this; }
    public function isLocked(): bool { return $this->lockedUntil !== null && $this->lockedUntil > new \DateTimeImmutable(); }
    public function lockUntil(?\DateTimeImmutable $until, ?string $reason): self
    {
        $this->lockedUntil = $until;
        $reason = $reason === null ? null : trim($reason);
        $this->lockReason = $reason === '' ? null : $reason;
        if ($until !== null) { $this->invalidateSessions(); }
        return $this;
    }
    public function unlock(): self { $this->lockedUntil = null; $this->lockReason = null; return $this; }
    public function getSecurityVersion(): int { return $this->securityVersion; }
    public function invalidateSessions(): self { ++$this->securityVersion; return $this; }
    public function getLastLoginAt(): ?\DateTimeImmutable { return $this->lastLoginAt; }
    public function markLogin(): self { $this->lastLoginAt = $this->lastSeenAt = new \DateTimeImmutable(); return $this; }
    public function getLastSeenAt(): ?\DateTimeImmutable { return $this->lastSeenAt; }
    public function markSeen(): self { $this->lastSeenAt = new \DateTimeImmutable(); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

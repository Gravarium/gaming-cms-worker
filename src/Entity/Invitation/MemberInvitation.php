<?php

declare(strict_types=1);

namespace App\Entity\Invitation;

use App\Entity\AccessRole;
use App\Entity\User;
use App\Repository\Invitation\MemberInvitationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MemberInvitationRepository::class)]
#[ORM\Table(name: 'member_invitation')]
#[ORM\UniqueConstraint(name: 'uniq_member_invitation_token', columns: ['token_hash'])]
class MemberInvitation
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REVOKED = 'revoked';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'access_role_id', onDelete: 'SET NULL')]
    private ?AccessRole $accessRole = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'accepted_user_id', onDelete: 'SET NULL')]
    private ?User $acceptedUser = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(name: 'token_hash', length: 64, unique: true)]
    private string $tokenHash;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $rolePermissionSnapshot = [];

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param list<string> $rolePermissionSnapshot */
    public function __construct(
        User $createdBy,
        string $email,
        string $tokenHash,
        ?AccessRole $accessRole,
        array $rolePermissionSnapshot,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
    ) {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 180) {
            throw new \InvalidArgumentException('Invalid invitation email.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $tokenHash) !== 1 || $expiresAt <= $now) {
            throw new \InvalidArgumentException('Invalid invitation token or expiry.');
        }

        $this->createdBy = $createdBy;
        $this->email = $email;
        $this->tokenHash = $tokenHash;
        $this->accessRole = $accessRole;
        $snapshot = array_values(array_unique($rolePermissionSnapshot));
        sort($snapshot);
        $this->rolePermissionSnapshot = $snapshot;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedBy(): User { return $this->createdBy; }
    public function getEmail(): string { return $this->email; }
    public function getAccessRole(): ?AccessRole { return $this->accessRole; }
    public function getAcceptedUser(): ?User { return $this->acceptedUser; }
    public function getStatus(): string { return $this->status; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function getUsedAt(): ?\DateTimeImmutable { return $this->usedAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** @return list<string> */
    public function getRolePermissionSnapshot(): array { return $this->rolePermissionSnapshot; }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        if ($this->status !== self::STATUS_PENDING || $this->expiresAt <= $now) {
            return false;
        }

        $role = $this->accessRole;
        if ($role === null) {
            return $this->rolePermissionSnapshot === [];
        }
        if (!$role->isActive()) {
            return false;
        }

        return array_diff($role->getPermissions(), $this->rolePermissionSnapshot) === [];
    }

    public function accept(User $user, \DateTimeImmutable $now): void
    {
        if (!$this->isUsable($now)) {
            throw new \DomainException('Invitation is not usable.');
        }
        if (!hash_equals($this->email, $user->getEmail())) {
            throw new \DomainException('Invitation email does not match account.');
        }

        $this->status = self::STATUS_ACCEPTED;
        $this->acceptedUser = $user;
        $this->usedAt = $now;
    }

    public function revoke(\DateTimeImmutable $now): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \DomainException('Only pending invitations can be revoked.');
        }
        $this->status = self::STATUS_REVOKED;
        $this->revokedAt = $now;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity\GameCharacter;

use App\Entity\Game;
use App\Entity\User;
use App\Repository\GameCharacter\CharacterAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharacterAccountRepository::class)]
#[ORM\Table(name: 'game_character_account')]
#[ORM\Index(name: 'idx_game_character_account_owner', columns: ['owner_id'])]
#[ORM\Index(name: 'idx_game_character_account_game', columns: ['game_id'])]
#[ORM\UniqueConstraint(name: 'uniq_game_character_account_owner_game_key', columns: ['owner_id', 'game_id', 'account_key'])]
#[ORM\HasLifecycleCallbacks]
final class CharacterAccount
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_IMPORTED = 'imported';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Game $game;

    #[ORM\Column(length: 160)]
    private string $accountKey;

    #[ORM\Column(length: 160)]
    private string $displayName;

    #[ORM\Column(length: 16)]
    private string $source;

    #[ORM\Column(options: ['default' => 1])]
    private int $consentVersion = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consentGrantedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, Game $game, string $accountKey, string $displayName, string $source = self::SOURCE_MANUAL)
    {
        $accountKey = trim($accountKey);
        $displayName = trim($displayName);

        if ($accountKey === '' || mb_strlen($accountKey) > 160) {
            throw new \InvalidArgumentException('A character account key is required and must be at most 160 characters.');
        }
        if ($displayName === '' || mb_strlen($displayName) > 160) {
            throw new \InvalidArgumentException('A character account display name is required and must be at most 160 characters.');
        }
        if (!in_array($source, [self::SOURCE_MANUAL, self::SOURCE_IMPORTED], true)) {
            throw new \InvalidArgumentException('Unknown character account source.');
        }

        $this->owner = $owner;
        $this->game = $game;
        $this->accountKey = $accountKey;
        $this->displayName = $displayName;
        $this->source = $source;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOwner(): User { return $this->owner; }
    public function getGame(): Game { return $this->game; }
    public function getAccountKey(): string { return $this->accountKey; }
    public function getDisplayName(): string { return $this->displayName; }
    public function setDisplayName(string $displayName): self
    {
        $displayName = trim($displayName);
        if ($displayName === '' || mb_strlen($displayName) > 160) {
            throw new \InvalidArgumentException('A character account display name is required and must be at most 160 characters.');
        }
        $this->displayName = $displayName;

        return $this;
    }
    public function getSource(): string { return $this->source; }
    public function isImported(): bool { return $this->source === self::SOURCE_IMPORTED; }
    public function getConsentVersion(): int { return $this->consentVersion; }
    public function getConsentGrantedAt(): ?\DateTimeImmutable { return $this->consentGrantedAt; }
    public function hasConsent(): bool { return $this->consentGrantedAt !== null && !$this->isDeleted(); }
    public function grantConsent(): self { $this->consentGrantedAt = new \DateTimeImmutable(); ++$this->consentVersion; return $this; }
    public function revokeConsent(): self { $this->consentGrantedAt = null; ++$this->consentVersion; return $this; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function isDeleted(): bool { return $this->deletedAt !== null; }
    public function markDeleted(): self { $this->deletedAt ??= new \DateTimeImmutable(); return $this; }
    public function isOwnedBy(?User $user): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        return $this->owner === $user
            || ($this->owner->getId() !== null && $user->getId() !== null && $this->owner->getId() === $user->getId());
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}

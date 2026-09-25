<?php

declare(strict_types=1);

namespace App\Entity\GameCharacter;

use App\Entity\Game;
use App\Repository\GameCharacter\CharacterProfileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CharacterProfileRepository::class)]
#[ORM\Table(name: 'game_character_profile')]
#[ORM\Index(name: 'idx_game_character_profile_account', columns: ['account_id'])]
#[ORM\Index(name: 'idx_game_character_profile_main', columns: ['main_profile_id'])]
#[ORM\Index(name: 'idx_game_character_profile_visibility', columns: ['visibility_default', 'deleted_at'])]
#[ORM\UniqueConstraint(name: 'uniq_game_character_profile_external', columns: ['account_id', 'external_id'])]
#[ORM\HasLifecycleCallbacks]
final class CharacterProfile
{
    public const SOURCE_MANUAL = CharacterAccount::SOURCE_MANUAL;
    public const SOURCE_IMPORTED = CharacterAccount::SOURCE_IMPORTED;

    public const FIELD_NAME = 'name';
    public const FIELD_SERVER = 'server';
    public const FIELD_REGION = 'region';
    public const FIELD_CLASS = 'class';
    public const FIELD_ROLE = 'role';
    public const FIELD_LEVEL = 'level';
    public const FIELD_BUILDS = 'builds';
    public const FIELD_PROFESSIONS = 'professions';
    public const FIELD_PROGRESSION = 'progression';
    public const FIELD_COLLECTIONS = 'collections';

    public const FIELDS = [
        self::FIELD_NAME,
        self::FIELD_SERVER,
        self::FIELD_REGION,
        self::FIELD_CLASS,
        self::FIELD_ROLE,
        self::FIELD_LEVEL,
        self::FIELD_BUILDS,
        self::FIELD_PROFESSIONS,
        self::FIELD_PROGRESSION,
        self::FIELD_COLLECTIONS,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CharacterAccount $account;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'main_profile_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $mainProfile = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $externalId;

    #[ORM\Column(length: 160)]
    private string $name;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $server = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $characterClass = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(nullable: true)]
    private ?int $level = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $builds = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $professions = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $progression = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $collections = [];

    #[ORM\Column(length: 16)]
    private string $source;

    #[ORM\Column(options: ['default' => false])]
    private bool $visibilityDefault = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $refreshVersion = 0;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $lastImportedHash = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastImportedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, CharacterFieldConsent> */
    #[ORM\OneToMany(mappedBy: 'profile', targetEntity: CharacterFieldConsent::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $consents;

    /** @var Collection<int, CharacterImportProvenance> */
    #[ORM\OneToMany(mappedBy: 'profile', targetEntity: CharacterImportProvenance::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['observedAt' => 'DESC'])]
    private Collection $provenance;

    public function __construct(CharacterAccount $account, string $name, string $source = self::SOURCE_MANUAL, ?string $externalId = null)
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new \InvalidArgumentException('A character profile name is required and must be at most 160 characters.');
        }
        if (!in_array($source, [self::SOURCE_MANUAL, self::SOURCE_IMPORTED], true)) {
            throw new \InvalidArgumentException('Unknown character profile source.');
        }
        if ($account->isDeleted()) {
            throw new \DomainException('A deleted character account cannot receive profiles.');
        }

        $this->account = $account;
        $this->name = $name;
        $this->source = $source;
        $this->externalId = self::optional($externalId);
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->consents = new ArrayCollection();
        $this->provenance = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getAccount(): CharacterAccount { return $this->account; }
    public function getGame(): Game { return $this->account->getGame(); }
    public function getMainProfile(): ?self { return $this->mainProfile; }
    public function setMainProfile(?self $mainProfile): self
    {
        if ($mainProfile === $this) {
            throw new \DomainException('A character profile cannot be its own main profile.');
        }
        if ($mainProfile !== null && $mainProfile->getAccount() !== $this->account) {
            throw new \DomainException('Main and alt profiles must belong to the same character account.');
        }
        if ($mainProfile?->isDeleted()) {
            throw new \DomainException('A deleted profile cannot be selected as the main profile.');
        }
        $this->mainProfile = $mainProfile;

        return $this;
    }
    public function getExternalId(): ?string { return $this->externalId; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = self::required($name, 160, 'Character name'); return $this; }
    public function getServer(): ?string { return $this->server; }
    public function setServer(?string $server): self { $this->server = self::optional($server, 120); return $this; }
    public function getRegion(): ?string { return $this->region; }
    public function setRegion(?string $region): self { $this->region = self::optional($region, 80); return $this; }
    public function getCharacterClass(): ?string { return $this->characterClass; }
    public function setCharacterClass(?string $characterClass): self { $this->characterClass = self::optional($characterClass, 100); return $this; }
    public function getRole(): ?string { return $this->role; }
    public function setRole(?string $role): self { $this->role = self::optional($role, 80); return $this; }
    public function getLevel(): ?int { return $this->level; }
    public function setLevel(?int $level): self
    {
        if ($level !== null && ($level < 1 || $level > 10000)) {
            throw new \InvalidArgumentException('Character level must be between 1 and 10000.');
        }
        $this->level = $level;

        return $this;
    }
    /** @param array<string, mixed> $builds */
    public function setBuilds(array $builds): self { $this->builds = $builds; return $this; }
    /** @return array<string, mixed> */
    public function getBuilds(): array { return $this->builds; }
    /** @param array<string, mixed> $professions */
    public function setProfessions(array $professions): self { $this->professions = $professions; return $this; }
    /** @return array<string, mixed> */
    public function getProfessions(): array { return $this->professions; }
    /** @param array<string, mixed> $progression */
    public function setProgression(array $progression): self { $this->progression = $progression; return $this; }
    /** @return array<string, mixed> */
    public function getProgression(): array { return $this->progression; }
    /** @param array<string, mixed> $collections */
    public function setCollections(array $collections): self { $this->collections = $collections; return $this; }
    /** @return array<string, mixed> */
    public function getCollections(): array { return $this->collections; }
    public function getSource(): string { return $this->source; }
    public function isImported(): bool { return $this->source === self::SOURCE_IMPORTED; }
    public function isManual(): bool { return $this->source === self::SOURCE_MANUAL; }
    public function isPubliclyVisible(): bool { return $this->visibilityDefault && !$this->isDeleted() && !$this->account->isDeleted() && $this->account->hasConsent(); }
    public function setPubliclyVisible(bool $visible): self { $this->visibilityDefault = $visible; return $this; }
    public function getRefreshVersion(): int { return $this->refreshVersion; }
    public function getLastImportedHash(): ?string { return $this->lastImportedHash; }
    public function getLastImportedAt(): ?\DateTimeImmutable { return $this->lastImportedAt; }
    public function getDeletedAt(): ?\DateTimeImmutable { return $this->deletedAt; }
    public function isDeleted(): bool { return $this->deletedAt !== null; }
    public function markDeleted(): self { $this->deletedAt ??= new \DateTimeImmutable(); $this->visibilityDefault = false; return $this; }

    public function grantFieldConsent(string $fieldKey, string $source = 'user'): self
    {
        $consent = $this->consentFor($fieldKey);
        if ($consent === null) {
            $consent = new CharacterFieldConsent($this, $fieldKey, $source);
            $this->consents->add($consent);
        } else {
            $consent->grant($source);
        }

        return $this;
    }
    public function revokeFieldConsent(string $fieldKey, string $source = 'user'): self
    {
        $consent = $this->consentFor($fieldKey);
        if ($consent === null) {
            $consent = new CharacterFieldConsent($this, $fieldKey, $source);
            $this->consents->add($consent);
        }
        $consent->revoke($source);

        return $this;
    }
    public function consentFor(string $fieldKey): ?CharacterFieldConsent
    {
        foreach ($this->consents as $consent) {
            if ($consent->getFieldKey() === $fieldKey) {
                return $consent;
            }
        }

        return null;
    }
    public function addProvenance(CharacterImportProvenance $provenance): self
    {
        if ($provenance->getProfile() !== $this) {
            throw new \DomainException('Import provenance must belong to this character profile.');
        }
        $this->provenance->add($provenance);

        return $this;
    }
    /** @return Collection<int, CharacterFieldConsent> */
    public function getConsents(): Collection { return $this->consents; }
    /** @return Collection<int, CharacterImportProvenance> */
    public function getProvenance(): Collection { return $this->provenance; }

    public function getFieldValue(string $fieldKey): mixed
    {
        return match ($fieldKey) {
            self::FIELD_NAME => $this->name,
            self::FIELD_SERVER => $this->server,
            self::FIELD_REGION => $this->region,
            self::FIELD_CLASS => $this->characterClass,
            self::FIELD_ROLE => $this->role,
            self::FIELD_LEVEL => $this->level,
            self::FIELD_BUILDS => $this->builds,
            self::FIELD_PROFESSIONS => $this->professions,
            self::FIELD_PROGRESSION => $this->progression,
            self::FIELD_COLLECTIONS => $this->collections,
            default => throw new \InvalidArgumentException('Unknown character profile field.'),
        };
    }

    /**
     * @param array<string, mixed> $builds
     * @param array<string, mixed> $professions
     * @param array<string, mixed> $progression
     * @param array<string, mixed> $collections
     */
    public function applyImportedData(
        string $name,
        ?string $server,
        ?string $region,
        ?string $characterClass,
        ?string $role,
        ?int $level,
        array $builds,
        array $professions,
        array $progression,
        array $collections,
        string $payloadHash,
        int $expectedRefreshVersion,
        \DateTimeImmutable $observedAt,
    ): self {
        if (!$this->isImported()) {
            throw new \DomainException('Manual character profiles cannot be overwritten by an import.');
        }
        if ($expectedRefreshVersion !== $this->refreshVersion) {
            throw new \DomainException('Character refresh is stale; reload the profile before importing again.');
        }
        if (trim($payloadHash) === '' || mb_strlen($payloadHash) > 128) {
            throw new \InvalidArgumentException('A bounded import payload hash is required.');
        }

        $this->setName($name);
        $this->setServer($server);
        $this->setRegion($region);
        $this->setCharacterClass($characterClass);
        $this->setRole($role);
        $this->setLevel($level);
        $this->setBuilds($builds);
        $this->setProfessions($professions);
        $this->setProgression($progression);
        $this->setCollections($collections);
        $this->lastImportedHash = trim($payloadHash);
        $this->lastImportedAt = $observedAt;
        ++$this->refreshVersion;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }

    private static function required(string $value, int $maxLength, string $label): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException($label.' is required and bounded.');
        }

        return $value;
    }
    private static function optional(?string $value, int $maxLength = 255): ?string
    {
        if ($value === null) { return null; }
        $value = trim($value);
        if ($value === '') { return null; }
        if (mb_strlen($value) > $maxLength) { throw new \InvalidArgumentException('Character field is too long.'); }

        return $value;
    }
}

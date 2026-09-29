<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExternalConnectorTargetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ExternalConnectorTargetRepository::class)]
#[ORM\Table(name: 'external_connector_target')]
#[ORM\UniqueConstraint(name: 'uniq_connector_capability_key', columns: ['capability', 'target_key'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['capability', 'targetKey'], message: 'Diese Zielkennung wird für die ausgewählte Fähigkeit bereits verwendet.')]
#[Assert\Expression(
    expression: 'not this.isEnabled() or this.getConfigurationReference() !== null',
    message: 'Ein aktives Ziel benötigt einen nicht geheimen Server-Konfigurationsverweis.',
)]
class ExternalConnectorTarget
{
    public const CAPABILITY_BACKUP = 'backup';
    public const CAPABILITY_MEDIA = 'media';
    public const CAPABILITY_MAIL = 'mail';
    public const CAPABILITY_NOTIFICATIONS = 'notifications';
    public const CAPABILITY_IDENTITY = 'identity';
    public const CAPABILITY_CDN = 'cdn';
    public const CAPABILITY_ANALYTICS = 'analytics';

    public const CAPABILITIES = [
        self::CAPABILITY_BACKUP,
        self::CAPABILITY_MEDIA,
        self::CAPABILITY_MAIL,
        self::CAPABILITY_NOTIFICATIONS,
        self::CAPABILITY_IDENTITY,
        self::CAPABILITY_CDN,
        self::CAPABILITY_ANALYTICS,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    #[Assert\Choice(choices: self::CAPABILITIES)]
    private string $capability = self::CAPABILITY_BACKUP;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9_.-]*$/')]
    private string $targetKey = '';

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9_.-]*$/')]
    private string $providerKey = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $displayName = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $enabled = false;

    #[ORM\Column(name: 'is_required', options: ['default' => true])]
    private bool $required = true;

    #[ORM\Column(options: ['default' => 100])]
    #[Assert\Range(min: 0, max: 10000)]
    private int $priority = 100;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9_.-]*$/')]
    private ?string $configurationReference = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCapability(): string { return $this->capability; }
    public function setCapability(string $capability): self { $this->capability = trim($capability); return $this; }
    public function getTargetKey(): string { return $this->targetKey; }
    public function setTargetKey(string $targetKey): self { $this->targetKey = strtolower(trim($targetKey)); return $this; }
    public function getProviderKey(): string { return $this->providerKey; }
    public function setProviderKey(string $providerKey): self { $this->providerKey = strtolower(trim($providerKey)); return $this; }
    public function getDisplayName(): string { return $this->displayName; }
    public function setDisplayName(string $displayName): self
    {
        if (!mb_check_encoding($displayName, 'UTF-8') || str_contains($displayName, "\0")) {
            throw new \InvalidArgumentException('Display name is invalid or exceeds the allowed length.');
        }

        $normalizedDisplayName = trim($displayName);
        if (mb_strlen($normalizedDisplayName, 'UTF-8') > 120 || strlen($normalizedDisplayName) > 480) {
            throw new \InvalidArgumentException('Display name is invalid or exceeds the allowed length.');
        }

        $this->displayName = $normalizedDisplayName;

        return $this;
    }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }
    public function isRequired(): bool { return $this->required; }
    public function setRequired(bool $required): self { $this->required = $required; return $this; }
    public function getPriority(): int { return $this->priority; }
    public function setPriority(int $priority): self { $this->priority = $priority; return $this; }
    public function getConfigurationReference(): ?string { return $this->configurationReference; }
    public function setConfigurationReference(?string $reference): self
    {
        $reference = $reference === null ? null : strtolower(trim($reference));
        $this->configurationReference = $reference === '' ? null : $reference;
        return $this;
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

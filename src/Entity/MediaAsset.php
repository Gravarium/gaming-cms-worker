<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MediaAssetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MediaAssetRepository::class)]
#[ORM\Table(name: 'media_asset')]
#[ORM\HasLifecycleCallbacks]
class MediaAsset
{
    private const MAX_TAG_TEXT_BYTES = 4096;
    private const MAX_TAG_CANDIDATES = 120;
    private const MAX_TAG_CANDIDATE_BYTES = 1024;

    public const DELETION_ACTIVE = 'active';
    public const DELETION_PENDING = 'pending';
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $moduleKey = '';

    #[ORM\Column(length: 10)]
    private string $storageMode = ModuleStorageSetting::MODE_INTERNAL;

    #[ORM\Column(length: 500)]
    private string $location = '';

    #[ORM\Column(length: 255)]
    private string $originalName = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $caption = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $tags = [];

    #[ORM\ManyToOne(inversedBy: 'assets')]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?MediaFolder $folder = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $checksumSha256 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $altText = null;

    #[ORM\Column(length: 12, options: ['default' => self::DELETION_ACTIVE])]
    private string $deletionState = self::DELETION_ACTIVE;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletionRequestedAt = null;

    /** @var Collection<int, MediaAssetReplica> */
    #[ORM\OneToMany(mappedBy: 'asset', targetEntity: MediaAssetReplica::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $replicas;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->replicas = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getModuleKey(): string { return $this->moduleKey; }
    public function setModuleKey(string $moduleKey): self { $this->moduleKey = trim($moduleKey); return $this; }
    public function getStorageMode(): string { return $this->storageMode; }
    public function setStorageMode(string $storageMode): self { $this->storageMode = $storageMode; return $this; }
    public function getLocation(): string { return $this->location; }
    public function setLocation(string $location): self { $this->location = $location; return $this; }
    public function getOriginalName(): string { return $this->originalName; }
    public function setOriginalName(string $originalName): self { $this->originalName = $originalName; return $this; }
    public function getTitle(): string { return $this->title !== '' ? $this->title : $this->originalName; }
    public function setTitle(string $title): self { $this->title = trim($title); return $this; }
    public function getCaption(): ?string { return $this->caption; }
    public function setCaption(?string $caption): self { $caption = $caption === null ? null : trim($caption); $this->caption = $caption === '' ? null : $caption; return $this; }
    /** @return list<string> */
    public function getTags(): array { return $this->tags; }
    /** @param array<array-key, mixed> $tags */
    public function setTags(array $tags): self
    {
        if (count($tags) > self::MAX_TAG_CANDIDATES || !array_is_list($tags)) {
            return $this;
        }

        $normalized = [];
        foreach ($tags as $tag) {
            if (
                !is_string($tag)
                || strlen($tag) > self::MAX_TAG_CANDIDATE_BYTES
                || !mb_check_encoding($tag, 'UTF-8')
            ) {
                continue;
            }

            $tag = mb_strtolower(trim($tag));
            if ($tag !== '' && mb_strlen($tag) <= 40) {
                $normalized[$tag] = true;
            }
        }

        $this->tags = array_slice(array_keys($normalized), 0, 30);

        return $this;
    }
    public function getTagsText(): string { return implode(', ', $this->tags); }
    public function setTagsText(?string $tags): self
    {
        if ($tags === null) {
            return $this->setTags([]);
        }

        if (strlen($tags) > self::MAX_TAG_TEXT_BYTES) {
            return $this;
        }

        $candidates = preg_split('/[,;]+/', $tags, self::MAX_TAG_CANDIDATES + 1);
        if ($candidates === false || count($candidates) > self::MAX_TAG_CANDIDATES) {
            return $this;
        }

        return $this->setTags($candidates);
    }
    public function getFolder(): ?MediaFolder { return $this->folder; }
    public function setFolder(?MediaFolder $folder): self { $this->folder = $folder; return $this; }
    public function getMimeType(): ?string { return $this->mimeType; }
    public function setMimeType(?string $mimeType): self { $this->mimeType = $mimeType; return $this; }
    public function getFileSize(): ?int { return $this->fileSize; }
    public function setFileSize(?int $fileSize): self { $this->fileSize = $fileSize; return $this; }
    public function getChecksumSha256(): ?string { return $this->checksumSha256; }
    public function setChecksumSha256(?string $checksum): self { $this->checksumSha256 = $checksum; return $this; }
    public function getAltText(): ?string { return $this->altText; }
    public function setAltText(?string $altText): self { $altText = $altText === null ? null : trim($altText); $this->altText = $altText === '' ? null : $altText; return $this; }
    public function getDeletionState(): string { return $this->deletionState; }
    public function isDeletionPending(): bool { return $this->deletionState === self::DELETION_PENDING; }
    public function getDeletionRequestedAt(): ?\DateTimeImmutable { return $this->deletionRequestedAt; }
    public function markDeletionPending(): self
    {
        $this->deletionState = self::DELETION_PENDING;
        $this->deletionRequestedAt ??= new \DateTimeImmutable();

        return $this;
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    /** @return Collection<int, MediaAssetReplica> */
    public function getReplicas(): Collection { return $this->replicas; }
    public function addReplica(MediaAssetReplica $replica): self
    {
        if (!$this->replicas->contains($replica)) { $this->replicas->add($replica); $replica->setAsset($this); }
        return $this;
    }
    public function removeReplica(MediaAssetReplica $replica): self
    {
        if ($this->replicas->removeElement($replica) && $replica->getAsset() === $this) { $replica->setAsset(null); }
        return $this;
    }
    public function isExternal(): bool { return $this->storageMode === ModuleStorageSetting::MODE_EXTERNAL; }
    public function isImage(): bool { return str_starts_with((string) $this->mimeType, 'image/'); }
    public function isVideo(): bool { return str_starts_with((string) $this->mimeType, 'video/'); }

    #[ORM\PreUpdate]
    public function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}

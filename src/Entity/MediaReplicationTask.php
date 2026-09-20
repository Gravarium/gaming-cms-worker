<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MediaReplicationTaskRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MediaReplicationTaskRepository::class)]
#[ORM\Table(name: 'media_replication_task')]
#[ORM\UniqueConstraint(name: 'uniq_media_replication_target', columns: ['media_asset_id', 'target_key'])]
class MediaReplicationTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'media_asset_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?MediaAsset $asset = null;

    #[ORM\Column(length: 64)]
    private string $targetKey = '';

    #[ORM\Column(length: 64)]
    private string $providerKey = '';

    #[ORM\Column(length: 500)]
    private string $objectKey = '';

    #[ORM\Column(length: 80)]
    private string $stagedFilename = '';

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getAsset(): ?MediaAsset { return $this->asset; }
    public function setAsset(MediaAsset $asset): self { $this->asset = $asset; return $this; }
    public function getTargetKey(): string { return $this->targetKey; }
    public function setTargetKey(string $value): self { $this->targetKey = strtolower(trim($value)); return $this; }
    public function getProviderKey(): string { return $this->providerKey; }
    public function setProviderKey(string $value): self { $this->providerKey = strtolower(trim($value)); return $this; }
    public function getObjectKey(): string { return $this->objectKey; }
    public function setObjectKey(string $value): self { $this->objectKey = ltrim(trim($value), '/'); return $this; }
    public function getStagedFilename(): string { return $this->stagedFilename; }
    public function setStagedFilename(string $value): self
    {
        if (preg_match('/^[a-f0-9]{32}\.bin$/', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid staged media filename.');
        }
        $this->stagedFilename = $value;
        return $this;
    }
    public function getAttempts(): int { return $this->attempts; }
    public function getLastAttemptAt(): ?\DateTimeImmutable { return $this->lastAttemptAt; }
    public function markAttempted(): self { ++$this->attempts; $this->lastAttemptAt = new \DateTimeImmutable(); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

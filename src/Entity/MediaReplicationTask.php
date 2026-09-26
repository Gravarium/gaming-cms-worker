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

    public function setTargetKey(string $value): self
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $value) !== 1 || strlen($value) > 64) {
            throw new \InvalidArgumentException('Invalid replication target key.');
        }
        $this->targetKey = $value;

        return $this;
    }

    public function getProviderKey(): string { return $this->providerKey; }

    public function setProviderKey(string $value): self
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $value) !== 1 || strlen($value) > 64) {
            throw new \InvalidArgumentException('Invalid replication provider key.');
        }
        $this->providerKey = $value;

        return $this;
    }

    public function getObjectKey(): string { return $this->objectKey; }

    public function setObjectKey(string $value): self
    {
        $value = trim($value);
        if ($value === ''
            || mb_strlen($value) > 500
            || str_starts_with($value, '/')
            || str_contains($value, '\\')
            || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new \InvalidArgumentException('Invalid replication object key.');
        }
        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Invalid replication object key.');
            }
        }
        $this->objectKey = $value;

        return $this;
    }

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

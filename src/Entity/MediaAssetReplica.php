<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MediaAssetReplicaRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MediaAssetReplicaRepository::class)]
#[ORM\Table(name: 'media_asset_replica')]
#[ORM\UniqueConstraint(name: 'uniq_media_replica_target', columns: ['media_asset_id', 'target_key'])]
#[UniqueEntity(fields: ['asset', 'targetKey'])]
class MediaAssetReplica
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'replicas')]
    #[ORM\JoinColumn(name: 'media_asset_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?MediaAsset $asset = null;

    #[ORM\Column(length: 64)]
    #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9_.-]*$/')]
    private string $targetKey = '';

    #[ORM\Column(length: 64)]
    #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9_.-]*$/')]
    private string $providerKey = '';

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    private string $objectKey = '';

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    private string $location = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getAsset(): ?MediaAsset { return $this->asset; }
    public function setAsset(?MediaAsset $asset): self { $this->asset = $asset; return $this; }
    public function getTargetKey(): string { return $this->targetKey; }

    public function setTargetKey(string $key): self
    {
        $key = strtolower(trim($key));
        if (preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $key) !== 1 || strlen($key) > 64) {
            throw new \InvalidArgumentException('Invalid media target key.');
        }
        $this->targetKey = $key;

        return $this;
    }

    public function getProviderKey(): string { return $this->providerKey; }

    public function setProviderKey(string $key): self
    {
        $key = strtolower(trim($key));
        if (preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $key) !== 1 || strlen($key) > 64) {
            throw new \InvalidArgumentException('Invalid media provider key.');
        }
        $this->providerKey = $key;

        return $this;
    }

    public function getObjectKey(): string { return $this->objectKey; }

    public function setObjectKey(string $key): self
    {
        $key = trim($key);
        if (!$this->isSafeObjectKey($key)) {
            throw new \InvalidArgumentException('Invalid media object key.');
        }
        $this->objectKey = $key;

        return $this;
    }

    public function getLocation(): string { return $this->location; }

    public function setLocation(string $location): self
    {
        $location = trim($location);
        if ($location === '' || mb_strlen($location) > 500 || preg_match('/[\x00-\x1F\x7F]/u', $location) === 1) {
            throw new \InvalidArgumentException('Invalid media replica location.');
        }
        $this->location = $location;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    private function isSafeObjectKey(string $key): bool
    {
        if ($key === '' || mb_strlen($key) > 500 || str_starts_with($key, '/') || str_contains($key, '\\') || preg_match('/[\x00-\x1F\x7F]/u', $key) === 1) {
            return false;
        }
        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity\Hardware;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hardware_product_revision')]
#[ORM\UniqueConstraint(name: 'UNIQ_HARDWARE_REVISION_VERSION', columns: ['product_id', 'version'])]
class HardwareProductRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HardwareProduct::class, inversedBy: 'revisions')]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
    private HardwareProduct $product;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\Column]
    private int $version = 1;

    #[ORM\Column(length: 500)]
    private string $summary = '';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getProduct(): HardwareProduct { return $this->product; }
    public function setProduct(HardwareProduct $product): self { $this->product = $product; return $this; }
    public function getActor(): ?User { return $this->actor; }
    public function setActor(?User $actor): self { $this->actor = $actor; return $this; }
    public function getVersion(): int { return $this->version; }
    public function setVersion(int $version): self { $this->version = $version; return $this; }
    public function getSummary(): string { return $this->summary; }
    public function setSummary(string $summary): self { $this->summary = trim($summary); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

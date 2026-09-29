<?php

declare(strict_types=1);

namespace App\Entity\Hardware;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hardware_community_setup')]
class HardwareCommunitySetup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $owner = null;

    /** @var list<int> */
    #[ORM\Column(name: 'product_ids', type: Types::JSON)]
    private array $productIds = [];

    #[ORM\Column(type: Types::TEXT)]
    private string $notes = '';

    #[ORM\Column]
    private bool $moderated = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(?User $owner): self { $this->owner = $owner; return $this; }
    /** @return list<int> */
    public function getProductIds(): array { return $this->productIds; }
    /** @param list<int> $productIds */
    public function setProductIds(array $productIds): self { $this->productIds = array_values($productIds); return $this; }
    public function getNotes(): string { return $this->notes; }
    public function setNotes(string $notes): self { $this->notes = trim($notes); return $this; }
    public function isModerated(): bool { return $this->moderated; }
    public function setModerated(bool $moderated): self { $this->moderated = $moderated; return $this; }
    public function isPublic(): bool { return $this->moderated; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

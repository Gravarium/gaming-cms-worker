<?php

declare(strict_types=1);

namespace App\Entity\Hardware;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hardware_specification')]
#[ORM\UniqueConstraint(name: 'UNIQ_HARDWARE_SPEC', columns: ['product_id', 'spec_key'])]
class HardwareSpecification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HardwareProduct::class, inversedBy: 'specifications')]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
    private HardwareProduct $product;

    #[ORM\Column(name: 'spec_key', length: 120)]
    private string $specKey = '';

    #[ORM\Column(type: Types::JSON)]
    private float|int|string|bool $value = '';

    #[ORM\Column(length: 32)]
    private string $unit = '';

    public function getId(): ?int { return $this->id; }
    public function getProduct(): HardwareProduct { return $this->product; }
    public function setProduct(HardwareProduct $product): self { $this->product = $product; return $this; }
    public function getSpecKey(): string { return $this->specKey; }
    public function setSpecKey(string $specKey): self { $this->specKey = $specKey; return $this; }
    public function getValue(): float|int|string|bool { return $this->value; }
    public function setValue(float|int|string|bool $value): self { $this->value = $value; return $this; }
    public function getUnit(): string { return $this->unit; }
    public function setUnit(string $unit): self { $this->unit = $unit; return $this; }
}

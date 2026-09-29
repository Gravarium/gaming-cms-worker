<?php

declare(strict_types=1);

namespace App\Entity\Hardware;

use App\Hardware\BenchmarkMeasurement;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hardware_benchmark_measurement')]
class HardwareBenchmarkMeasurement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: HardwareProduct::class, inversedBy: 'measurements')]
    #[ORM\JoinColumn(name: 'product_id', nullable: false, onDelete: 'CASCADE')]
    private HardwareProduct $product;

    #[ORM\ManyToOne(targetEntity: HardwareBenchmarkMethodology::class)]
    #[ORM\JoinColumn(name: 'methodology_id', nullable: false, onDelete: 'RESTRICT')]
    private HardwareBenchmarkMethodology $methodology;

    #[ORM\Column(length: 180)]
    private string $series = '';

    #[ORM\Column(type: Types::FLOAT)]
    private float $value = 0.0;

    #[ORM\Column(length: 32)]
    private string $unit = '';

    #[ORM\Column(name: 'sample_count')]
    private int $sampleCount = 1;

    #[ORM\Column(name: 'source_type', length: 16)]
    private string $sourceType = 'editorial';

    #[ORM\Column(name: 'measured_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $measuredAt;

    public function __construct() { $this->measuredAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getProduct(): HardwareProduct { return $this->product; }
    public function setProduct(HardwareProduct $product): self { $this->product = $product; return $this; }
    public function getMethodology(): HardwareBenchmarkMethodology { return $this->methodology; }
    public function setMethodology(HardwareBenchmarkMethodology $methodology): self { $this->methodology = $methodology; return $this; }
    public function getSeries(): string { return $this->series; }
    public function setSeries(string $series): self { $this->series = trim($series); return $this; }
    public function getValue(): float { return $this->value; }
    public function setValue(float $value): self { $this->value = $value; return $this; }
    public function getUnit(): string { return $this->unit; }
    public function setUnit(string $unit): self { $this->unit = trim($unit); return $this; }
    public function getSampleCount(): int { return $this->sampleCount; }
    public function setSampleCount(int $sampleCount): self { $this->sampleCount = $sampleCount; return $this; }
    public function getSourceType(): string { return $this->sourceType; }
    public function setSourceType(string $sourceType): self { $this->sourceType = $sourceType; return $this; }
    public function getMeasuredAt(): \DateTimeImmutable { return $this->measuredAt; }
    public function setMeasuredAt(\DateTimeImmutable $measuredAt): self { $this->measuredAt = $measuredAt; return $this; }

    public function toDomainValue(): BenchmarkMeasurement
    {
        if ($this->product->getId() === null) { throw new \LogicException('A persisted hardware product is required.'); }

        return new BenchmarkMeasurement($this->product->getId(), $this->series, $this->value, $this->unit, $this->sampleCount, $this->sourceType);
    }
}

<?php

declare(strict_types=1);

namespace App\Entity\Hardware;

use App\Repository\Hardware\HardwareProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: HardwareProductRepository::class)]
#[ORM\Table(name: 'hardware_product')]
class HardwareProduct
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $name = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $category = '';

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 160)]
    private string $manufacturer = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 5000)]
    private string $disclosure = '';

    #[ORM\Column]
    private bool $published = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, HardwareSpecification> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: HardwareSpecification::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['specKey' => 'ASC'])]
    private Collection $specifications;

    /** @var Collection<int, HardwareBenchmarkMeasurement> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: HardwareBenchmarkMeasurement::class)]
    #[ORM\OrderBy(['measuredAt' => 'DESC', 'id' => 'DESC'])]
    private Collection $measurements;

    /** @var Collection<int, HardwareProductRevision> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: HardwareProductRevision::class)]
    #[ORM\OrderBy(['version' => 'DESC'])]
    private Collection $revisions;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->specifications = new ArrayCollection();
        $this->measurements = new ArrayCollection();
        $this->revisions = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getCategory(): string { return $this->category; }
    public function setCategory(string $category): self { $this->category = trim($category); return $this; }
    public function getManufacturer(): string { return $this->manufacturer; }
    public function setManufacturer(string $manufacturer): self { $this->manufacturer = trim($manufacturer); return $this; }
    public function getDisclosure(): string { return $this->disclosure; }
    public function setDisclosure(string $disclosure): self { $this->disclosure = trim($disclosure); return $this; }
    public function isPublished(): bool { return $this->published; }
    public function setPublished(bool $published): self { $this->published = $published; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, HardwareSpecification> */
    public function getSpecifications(): Collection { return $this->specifications; }
    public function addSpecification(HardwareSpecification $specification): self
    {
        if (!$this->specifications->contains($specification)) {
            $this->specifications->add($specification);
            $specification->setProduct($this);
        }

        return $this;
    }
    public function clearSpecifications(): self { $this->specifications->clear(); return $this; }

    /** @return Collection<int, HardwareBenchmarkMeasurement> */
    public function getMeasurements(): Collection { return $this->measurements; }

    /** @return Collection<int, HardwareProductRevision> */
    public function getRevisions(): Collection { return $this->revisions; }
}

<?php

declare(strict_types=1);

namespace App\Entity\Hardware;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hardware_benchmark_methodology')]
class HardwareBenchmarkMethodology
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $name = '';

    #[ORM\Column(length: 80)]
    private string $version = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $procedure = '';

    /** @var array<string, string> */
    #[ORM\Column(name: 'test_system', type: Types::JSON)]
    private array $testSystem = [];

    #[ORM\Column(type: Types::TEXT)]
    private string $disclosure = '';

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getVersion(): string { return $this->version; }
    public function setVersion(string $version): self { $this->version = trim($version); return $this; }
    public function getProcedure(): string { return $this->procedure; }
    public function setProcedure(string $procedure): self { $this->procedure = trim($procedure); return $this; }
    /** @return array<string, string> */
    public function getTestSystem(): array { return $this->testSystem; }
    /** @param array<string, string> $testSystem */
    public function setTestSystem(array $testSystem): self { $this->testSystem = $testSystem; return $this; }
    public function getDisclosure(): string { return $this->disclosure; }
    public function setDisclosure(string $disclosure): self { $this->disclosure = trim($disclosure); return $this; }
}

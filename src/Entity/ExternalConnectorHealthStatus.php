<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExternalConnectorHealthStatusRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExternalConnectorHealthStatusRepository::class)]
#[ORM\Table(name: 'external_connector_health_status')]
#[ORM\UniqueConstraint(name: 'uniq_connector_health_target', columns: ['capability', 'target_key'])]
class ExternalConnectorHealthStatus
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $capability = '';

    #[ORM\Column(length: 64)]
    private string $targetKey = '';

    #[ORM\Column(length: 64)]
    private string $providerKey = '';

    #[ORM\Column]
    private bool $successful = false;

    #[ORM\Column]
    private \DateTimeImmutable $checkedAt;

    public function __construct()
    {
        $this->checkedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCapability(): string { return $this->capability; }
    public function setCapability(string $capability): self { $this->capability = trim($capability); return $this; }
    public function getTargetKey(): string { return $this->targetKey; }
    public function setTargetKey(string $targetKey): self { $this->targetKey = strtolower(trim($targetKey)); return $this; }
    public function getProviderKey(): string { return $this->providerKey; }
    public function setProviderKey(string $providerKey): self { $this->providerKey = strtolower(trim($providerKey)); return $this; }
    public function isSuccessful(): bool { return $this->successful; }
    public function setSuccessful(bool $successful): self { $this->successful = $successful; return $this; }
    public function getCheckedAt(): \DateTimeImmutable { return $this->checkedAt; }
    public function setCheckedAt(\DateTimeImmutable $checkedAt): self { $this->checkedAt = $checkedAt; return $this; }
}

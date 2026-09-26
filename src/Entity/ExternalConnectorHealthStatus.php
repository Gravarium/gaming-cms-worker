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
    public function setCapability(string $capability): self
    {
        $this->capability = $this->normalizeBoundedIdentifier($capability, 40, 160);

        return $this;
    }
    public function getTargetKey(): string { return $this->targetKey; }
    public function setTargetKey(string $targetKey): self
    {
        $this->targetKey = $this->normalizeBoundedIdentifier($targetKey, 64, 256, true);

        return $this;
    }
    public function getProviderKey(): string { return $this->providerKey; }
    public function setProviderKey(string $providerKey): self
    {
        $this->providerKey = $this->normalizeBoundedIdentifier($providerKey, 64, 256, true);

        return $this;
    }
    public function isSuccessful(): bool { return $this->successful; }
    public function setSuccessful(bool $successful): self { $this->successful = $successful; return $this; }
    public function getCheckedAt(): \DateTimeImmutable { return $this->checkedAt; }
    public function setCheckedAt(\DateTimeImmutable $checkedAt): self { $this->checkedAt = $checkedAt; return $this; }

    private function normalizeBoundedIdentifier(string $value, int $maxLength, int $maxBytes, bool $lowercase = false): string
    {
        if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('External connector health identifier is invalid or exceeds its storage boundary.');
        }

        $normalized = trim($value);
        if ($lowercase) {
            $normalized = strtolower($normalized);
        }

        if (mb_strlen($normalized, 'UTF-8') > $maxLength || strlen($normalized) > $maxBytes) {
            throw new \InvalidArgumentException('External connector health identifier is invalid or exceeds its storage boundary.');
        }

        return $normalized;
    }

}

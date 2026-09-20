<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CmsModuleStateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CmsModuleStateRepository::class)]
#[ORM\Table(name: 'cms_module_state')]
class CmsModuleState
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $moduleKey = '';

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $installed = true;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $installedVersion = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $installedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getModuleKey(): string { return $this->moduleKey; }
    public function setModuleKey(string $key): self { $this->moduleKey = strtolower(trim($key)); return $this; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; $this->touch(); return $this; }
    public function isInstalled(): bool { return $this->installed; }
    public function getInstalledVersion(): ?string { return $this->installedVersion; }
    public function getInstalledAt(): ?\DateTimeImmutable { return $this->installedAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function install(string $version): self
    {
        $this->installed = true;
        $this->installedVersion = $version;
        $this->installedAt ??= new \DateTimeImmutable();
        $this->enabled = false;
        $this->touch();

        return $this;
    }

    public function updateVersion(string $version): self
    {
        $this->installedVersion = $version;
        $this->touch();

        return $this;
    }

    public function removePackage(): self
    {
        $this->installed = false;
        $this->enabled = false;
        $this->touch();

        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

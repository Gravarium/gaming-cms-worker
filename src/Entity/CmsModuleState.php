<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CmsModuleStateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CmsModuleStateRepository::class)]
#[ORM\Table(name: 'cms_module_state')]
class CmsModuleState
{
    private const MAX_MODULE_KEY_BYTES = 256;
    private const MAX_MODULE_KEY_LENGTH = 64;
    private const MAX_VERSION_BYTES = 128;
    private const MAX_VERSION_LENGTH = 32;

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
    public function setModuleKey(string $key): self
    {
        $this->moduleKey = $this->normalizeModuleKey($key);

        return $this;
    }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; $this->touch(); return $this; }
    public function isInstalled(): bool { return $this->installed; }
    public function getInstalledVersion(): ?string { return $this->installedVersion; }
    public function getInstalledAt(): ?\DateTimeImmutable { return $this->installedAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function install(string $version): self
    {
        $version = $this->validateVersion($version);
        $this->installed = true;
        $this->installedVersion = $version;
        $this->installedAt ??= new \DateTimeImmutable();
        $this->enabled = false;
        $this->touch();

        return $this;
    }

    public function updateVersion(string $version): self
    {
        $version = $this->validateVersion($version);
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

    private function normalizeModuleKey(string $key): string
    {
        if (strlen($key) > self::MAX_MODULE_KEY_BYTES || !mb_check_encoding($key, 'UTF-8')) {
            throw new \InvalidArgumentException('Der Modulschlüssel ist ungültig oder überschreitet die zulässige Länge.');
        }

        $normalized = strtolower(trim($key));
        if (mb_strlen($normalized, 'UTF-8') > self::MAX_MODULE_KEY_LENGTH) {
            throw new \InvalidArgumentException('Der Modulschlüssel ist ungültig oder überschreitet die zulässige Länge.');
        }

        return $normalized;
    }

    private function validateVersion(string $version): string
    {
        if (strlen($version) > self::MAX_VERSION_BYTES
            || !mb_check_encoding($version, 'UTF-8')
            || mb_strlen($version, 'UTF-8') > self::MAX_VERSION_LENGTH
        ) {
            throw new \InvalidArgumentException('Die Modulversion ist ungültig oder überschreitet die zulässige Länge.');
        }

        return $version;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

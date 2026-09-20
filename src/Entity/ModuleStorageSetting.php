<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ModuleStorageSettingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ModuleStorageSettingRepository::class)]
#[ORM\Table(name: 'module_storage_setting')]
#[ORM\UniqueConstraint(name: 'uniq_module_storage_key', columns: ['module_key'])]
#[UniqueEntity(fields: ['moduleKey'])]
class ModuleStorageSetting
{
    public const MODE_INTERNAL = 'internal';
    public const MODE_EXTERNAL = 'external';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z][a-z0-9_]*$/')]
    private string $moduleKey = '';

    #[ORM\Column(length: 10)]
    #[Assert\Choice(choices: [self::MODE_INTERNAL, self::MODE_EXTERNAL])]
    private string $storageMode = self::MODE_INTERNAL;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(requireTld: true)]
    #[Assert\Length(max: 500)]
    private ?string $externalBaseUrl = null;

    public function getId(): ?int { return $this->id; }
    public function getModuleKey(): string { return $this->moduleKey; }
    public function setModuleKey(string $moduleKey): self { $this->moduleKey = $moduleKey; return $this; }
    public function getStorageMode(): string { return $this->storageMode; }
    public function setStorageMode(string $storageMode): self { $this->storageMode = $storageMode; return $this; }
    public function getExternalBaseUrl(): ?string { return $this->externalBaseUrl; }
    public function setExternalBaseUrl(?string $externalBaseUrl): self
    {
        $externalBaseUrl = $externalBaseUrl === null ? null : rtrim(trim($externalBaseUrl), '/');
        $this->externalBaseUrl = $externalBaseUrl === '' ? null : $externalBaseUrl;
        return $this;
    }
}

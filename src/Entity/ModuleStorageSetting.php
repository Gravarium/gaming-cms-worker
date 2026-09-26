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

    private const MAX_MODULE_KEY_BYTES = 200;
    private const MAX_MODULE_KEY_LENGTH = 50;
    private const MAX_EXTERNAL_BASE_URL_BYTES = 2000;
    private const MAX_EXTERNAL_BASE_URL_LENGTH = 500;

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
    #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
    #[Assert\Length(max: 500)]
    private ?string $externalBaseUrl = null;

    public function getId(): ?int { return $this->id; }
    public function getModuleKey(): string { return $this->moduleKey; }
    public function setModuleKey(string $moduleKey): self
    {
        $this->assertColumnBoundedUtf8($moduleKey, self::MAX_MODULE_KEY_BYTES, self::MAX_MODULE_KEY_LENGTH, 'Der Modulschlüssel');

        $this->moduleKey = $moduleKey;

        return $this;
    }
    public function getStorageMode(): string { return $this->storageMode; }
    public function setStorageMode(string $storageMode): self { $this->storageMode = $storageMode; return $this; }
    public function getExternalBaseUrl(): ?string { return $this->externalBaseUrl; }
    public function setExternalBaseUrl(?string $externalBaseUrl): self
    {
        if ($externalBaseUrl !== null) {
            $this->assertColumnBoundedUtf8($externalBaseUrl, self::MAX_EXTERNAL_BASE_URL_BYTES, self::MAX_EXTERNAL_BASE_URL_LENGTH, 'Die externe Basis-URL');
            $externalBaseUrl = rtrim(trim($externalBaseUrl), '/');
        }

        $this->externalBaseUrl = $externalBaseUrl === '' ? null : $externalBaseUrl;

        return $this;
    }

    private function assertColumnBoundedUtf8(string $value, int $maxBytes, int $maxCharacters, string $field): void
    {
        if (strlen($value) > $maxBytes || !mb_check_encoding($value, 'UTF-8')) {
            throw new \\InvalidArgumentException($field.' ist ungültig oder überschreitet die zulässige Länge.');
        }

        if (str_contains($value, "\\0") || mb_strlen($value, 'UTF-8') > $maxCharacters) {
            throw new \\InvalidArgumentException($field.' ist ungültig oder überschreitet die zulässige Länge.');
        }
    }
}

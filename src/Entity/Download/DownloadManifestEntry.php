<?php

declare(strict_types=1);

namespace App\Entity\Download;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'download_manifest_entry')]
#[ORM\UniqueConstraint(name: 'uniq_download_manifest_item', columns: ['modpack_version_id', 'package_id'])]
class DownloadManifestEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DownloadVersion $modpackVersion;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DownloadPackage $package;

    #[ORM\Column(length: 80)]
    private string $versionConstraint;

    #[ORM\Column(options: ['default' => true])]
    private bool $required = true;

    public function __construct(
        DownloadVersion $modpackVersion,
        DownloadPackage $package,
        string $versionConstraint,
        bool $required = true,
    ) {
        if ($modpackVersion->getPackage()->getType() !== 'modpack') {
            throw new \DomainException('Manifest owner must be a modpack.');
        }
        if ($modpackVersion->getPackage() === $package) {
            throw new \DomainException('Modpack cannot contain itself.');
        }

        $versionConstraint = trim($versionConstraint);
        if ($versionConstraint === '') {
            throw new \InvalidArgumentException('Version constraint required.');
        }

        $this->modpackVersion = $modpackVersion;
        $this->package = $package;
        $this->versionConstraint = $versionConstraint;
        $this->required = $required;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getVersionConstraint(): string
    {
        return $this->versionConstraint;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity\Download;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'download_package')]
#[ORM\UniqueConstraint(name: 'uniq_download_package_slug', columns: ['slug'])]
class DownloadPackage
{
    public const TYPES = ['file', 'mod', 'addon', 'modpack'];
    public const VISIBILITIES = ['public', 'member', 'admin'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(length: 200)]
    private string $slug;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(length: 20)]
    private string $visibility = 'public';

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    public function __construct(string $title, string $slug, string $type)
    {
        $title = trim($title);
        $slug = trim($slug);
        if ($title === '' || $slug === '' || !in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Invalid download package.');
        }

        $this->title = $title;
        $this->slug = $slug;
        $this->type = $type;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function setVisibility(string $visibility): self
    {
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            throw new \InvalidArgumentException('Invalid visibility.');
        }

        $this->visibility = $visibility;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }
}

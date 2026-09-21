<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MediaFolderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MediaFolderRepository::class)]
#[ORM\Table(name: 'media_folder')]
#[ORM\UniqueConstraint(name: 'uniq_media_folder_slug', columns: ['slug'])]
class MediaFolder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column(length: 140)]
    private string $slug = '';

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?self $parent = null;

    /** @var Collection<int, MediaAsset> */
    #[ORM\OneToMany(mappedBy: 'folder', targetEntity: MediaAsset::class)]
    private Collection $assets;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->assets = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = trim($slug); return $this; }
    public function getParent(): ?self { return $this->parent; }

    public function setParent(?self $parent): self
    {
        if ($parent === null) {
            $this->parent = null;

            return $this;
        }

        $seen = [];
        $cursor = $parent;
        for ($depth = 0; $cursor !== null && $depth < 128; ++$depth) {
            if ($cursor === $this) {
                throw new \DomainException('Ein Medienordner darf nicht in sich selbst oder einen eigenen Unterordner verschoben werden.');
            }

            $objectId = spl_object_id($cursor);
            if (isset($seen[$objectId])) {
                throw new \DomainException('Die ausgewählte Ordnerhierarchie enthält bereits einen Zyklus.');
            }
            $seen[$objectId] = true;
            $cursor = $cursor->getParent();
        }

        if ($cursor !== null) {
            throw new \DomainException('Die ausgewählte Ordnerhierarchie ist zu tief.');
        }

        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, MediaAsset> */
    public function getAssets(): Collection { return $this->assets; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getPathLabel(): string
    {
        $parts = [];
        $seen = [];
        $cursor = $this;

        $depth = 0;
        $invalidHierarchy = false;
        while ($cursor !== null && $depth < 128) {
            $objectId = spl_object_id($cursor);
            if (isset($seen[$objectId])) {
                $invalidHierarchy = true;
                break;
            }
            $seen[$objectId] = true;
            array_unshift($parts, $cursor->getName());
            $cursor = $cursor->getParent();
            ++$depth;
        }

        if ($invalidHierarchy) {
            array_unshift($parts, '[Ungültige Hierarchie]');
        } elseif ($cursor !== null) {
            array_unshift($parts, '[Zu tiefe Hierarchie]');
        }

        return implode(' / ', $parts);
    }
}

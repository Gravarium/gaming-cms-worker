<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentTagRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ContentTagRepository::class)]
#[ORM\Table(name: 'content_tag')]
#[ORM\UniqueConstraint(name: 'uniq_content_tag_slug', columns: ['slug'])]
#[UniqueEntity(fields: ['slug'])]
class ContentTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name = '';

    #[ORM\Column(length: 120)]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $description = null;

    /** @var Collection<int, ContentEntry> */
    #[ORM\ManyToMany(targetEntity: ContentEntry::class, mappedBy: 'tags')]
    private Collection $entries;

    public function __construct()
    {
        $this->entries = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self
    {
        if (strlen($slug) > 512 || !mb_check_encoding($slug, 'UTF-8')) {
            throw new \InvalidArgumentException('Taxonomy slug input is invalid or too large.');
        }

        $normalized = trim(mb_strtolower($slug));
        if (strlen($normalized) > 120 || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $normalized) !== 1) {
            throw new \InvalidArgumentException('Taxonomy slug must be a non-empty lowercase ASCII slug of at most 120 characters.');
        }

        $this->slug = $normalized;

        return $this;
    }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self
    {
        $description = $description === null ? null : trim($description);
        $this->description = $description === '' ? null : $description;
        return $this;
    }
    /** @return Collection<int, ContentEntry> */
    public function getEntries(): Collection { return $this->entries; }
}

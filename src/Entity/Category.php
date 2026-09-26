<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Table(name: 'content_category')]
#[ORM\UniqueConstraint(name: 'uniq_content_category_slug', columns: ['slug'])]
#[UniqueEntity(fields: ['slug'])]
class Category
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
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?self $parent = null;
    /** @var Collection<int, self> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $children;

    public function __construct() { $this->children = new ArrayCollection(); }
    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self
    {
        if (strlen($slug) > 512 || !mb_check_encoding($slug, 'UTF-8')) {
            throw new \\InvalidArgumentException('Taxonomy slug input is invalid or too large.');
        }

        $normalized = trim(mb_strtolower($slug));
        if (strlen($normalized) > 120 || preg_match('/\\A[a-z0-9]+(?:-[a-z0-9]+)*\\z/', $normalized) !== 1) {
            throw new \\InvalidArgumentException('Taxonomy slug must be a non-empty lowercase ASCII slug of at most 120 characters.');
        }

        $this->slug = $normalized;

        return $this;
    }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $description = $description === null ? null : trim($description); $this->description = $description === '' ? null : $description; return $this; }
    public function getParent(): ?self { return $this->parent; }
    public function setParent(?self $parent): self
    {
        $this->assertValidParent($parent);
        $this->parent = $parent;

        return $this;
    }

    #[Assert\Callback]
    public function validateHierarchy(ExecutionContextInterface $context): void
    {
        try {
            $this->assertValidParent($this->parent);
        } catch (\DomainException $exception) {
            $context->buildViolation($exception->getMessage())->atPath('parent')->addViolation();
        }
    }

    /** @return Collection<int, self> */
    public function getChildren(): Collection { return $this->children; }

    public function getDisplayName(): string
    {
        $names = [];
        $seen = [];
        $cursor = $this;
        while ($cursor !== null) {
            $id = spl_object_id($cursor);
            if (isset($seen[$id]) || count($names) >= 100) {
                throw new \DomainException('Die Kategorie-Hierarchie ist zyklisch oder zu tief.');
            }
            $seen[$id] = true;
            array_unshift($names, $cursor->getName());
            $cursor = $cursor->getParent();
        }

        return implode(' / ', $names);
    }

    private function assertValidParent(?self $parent): void
    {
        $seen = [spl_object_id($this) => true];
        $depth = 0;
        for ($cursor = $parent; $cursor !== null; $cursor = $cursor->getParent()) {
            $id = spl_object_id($cursor);
            if (isset($seen[$id])) {
                throw new \DomainException('Die Kategorie-Hierarchie darf keinen Zyklus enthalten.');
            }
            $seen[$id] = true;
            if (++$depth >= 100) {
                throw new \DomainException('Die Kategorie-Hierarchie ist zu tief.');
            }
        }
    }
}

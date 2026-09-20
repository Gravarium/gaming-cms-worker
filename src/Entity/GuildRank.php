<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GuildRankRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GuildRankRepository::class)]
#[ORM\Table(name: 'guild_rank')]
class GuildRank
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Guild $guild = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name = '';

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'Bitte eine gültige Hex-Farbe verwenden.')]
    private ?string $color = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $defaultRank = false;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $permissions = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    public function getId(): ?int { return $this->id; }
    public function getGuild(): ?Guild { return $this->guild; }
    public function setGuild(Guild $guild): self { $this->guild = $guild; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color === null || trim($color) === '' ? null : trim($color); return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }
    public function isDefaultRank(): bool { return $this->defaultRank; }
    public function setDefaultRank(bool $defaultRank): self { $this->defaultRank = $defaultRank; return $this; }
    /** @return list<string> */
    public function getPermissions(): array { return $this->permissions; }
    /** @param list<string> $permissions */
    public function setPermissions(array $permissions): self { $this->permissions = array_values(array_unique($permissions)); return $this; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }
    public function __toString(): string { return $this->name; }
}

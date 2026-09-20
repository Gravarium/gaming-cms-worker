<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GuildRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GuildRepository::class)]
#[ORM\Table(name: 'guild')]
#[ORM\UniqueConstraint(name: 'uniq_guild_slug', columns: ['slug'])]
#[UniqueEntity(fields: ['slug'])]
class Guild
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Game $game = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?MediaAsset $logo = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column(length: 140)]
    private string $slug = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $serverName = '';

    #[ORM\Column(length: 60, nullable: true)]
    #[Assert\Length(max: 60)]
    private ?string $region = null;

    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $faction = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $description = '';

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(requireTld: true)]
    #[Assert\Length(max: 500)]
    private ?string $websiteUrl = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $recruitmentOpen = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getGame(): ?Game { return $this->game; }
    public function setGame(Game $game): self { $this->game = $game; return $this; }
    public function getLogo(): ?MediaAsset { return $this->logo; }
    public function setLogo(?MediaAsset $logo): self { $this->logo = $logo; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = $slug; return $this; }
    public function getServerName(): string { return $this->serverName; }
    public function setServerName(string $serverName): self { $this->serverName = trim($serverName); return $this; }
    public function getRegion(): ?string { return $this->region; }
    public function setRegion(?string $region): self { $this->region = $this->optional($region); return $this; }
    public function getFaction(): ?string { return $this->faction; }
    public function setFaction(?string $faction): self { $this->faction = $this->optional($faction); return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = trim($description); return $this; }
    public function getWebsiteUrl(): ?string { return $this->websiteUrl; }
    public function setWebsiteUrl(?string $websiteUrl): self { $this->websiteUrl = $this->optional($websiteUrl); return $this; }
    public function isRecruitmentOpen(): bool { return $this->recruitmentOpen; }
    public function setRecruitmentOpen(bool $recruitmentOpen): self { $this->recruitmentOpen = $recruitmentOpen; return $this; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    private function optional(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value === '' ? null : $value;
    }
}

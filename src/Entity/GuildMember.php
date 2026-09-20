<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GuildMemberRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GuildMemberRepository::class)]
#[ORM\Table(name: 'guild_member')]
class GuildMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Guild $guild = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?GuildRank $rank = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $characterName = '';

    #[ORM\Column(length: 100)]
    #[Assert\Length(max: 100)]
    private string $rankName = '';

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $characterClass = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $characterLevel = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $playerName = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $leader = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function getId(): ?int { return $this->id; }
    public function getGuild(): ?Guild { return $this->guild; }
    public function setGuild(Guild $guild): self
    {
        if ($this->rank !== null && $this->rank->getGuild() !== null && $this->rank->getGuild() !== $guild) {
            throw new \DomainException('A guild member cannot use a rank from another guild.');
        }
        $this->guild = $guild;
        return $this;
    }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getRank(): ?GuildRank { return $this->rank; }
    public function setRank(?GuildRank $rank): self
    {
        if ($rank !== null && $this->guild !== null && $rank->getGuild() !== null && $rank->getGuild() !== $this->guild) {
            throw new \DomainException('A guild member cannot use a rank from another guild.');
        }
        $this->rank = $rank;
        if ($rank !== null) { $this->rankName = $rank->getName(); }
        return $this;
    }
    public function getCharacterName(): string { return $this->characterName; }
    public function setCharacterName(string $characterName): self { $this->characterName = trim($characterName); return $this; }
    public function getRankName(): string { return $this->rankName; }
    public function getDisplayRank(): string { return $this->rank?->getName() ?? ($this->rankName !== '' ? $this->rankName : 'Mitglied'); }
    public function setRankName(string $rankName): self { $this->rankName = trim($rankName); return $this; }
    public function getCharacterClass(): ?string { return $this->characterClass; }
    public function setCharacterClass(?string $value): self { $this->characterClass = $this->optional($value); return $this; }
    public function getCharacterLevel(): ?int { return $this->characterLevel; }
    public function setCharacterLevel(?int $level): self { $this->characterLevel = $level; return $this; }
    public function getPlayerName(): ?string { return $this->playerName; }
    public function setPlayerName(?string $value): self { $this->playerName = $this->optional($value); return $this; }
    public function isLeader(): bool { return $this->leader; }
    public function setLeader(bool $leader): self { $this->leader = $leader; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    private function optional(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value === '' ? null : $value;
    }
}

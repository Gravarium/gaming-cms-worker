<?php

declare(strict_types=1);

namespace App\Entity\Guild;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildMember;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'guild_character_profile')]
#[ORM\UniqueConstraint(name: 'uniq_guild_character_name', columns: ['guild_id', 'game_id', 'character_name'])]
class GuildCharacterProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Guild $guild;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GuildMember $member;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Game $game;

    #[ORM\Column(length: 120)]
    private string $characterName;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $characterClass = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $mainCharacter = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct(Guild $guild, GuildMember $member, Game $game, string $characterName)
    {
        if ($member->getGuild() !== $guild) {
            throw new \DomainException('Character profile member must belong to the same guild.');
        }
        if ($guild->getGame() !== null && $game !== $guild->getGame()) {
            throw new \DomainException('Character profile game must match the guild game.');
        }
        $characterName = trim($characterName);
        if ($characterName === '') {
            throw new \InvalidArgumentException('Character name is required.');
        }

        $this->guild = $guild;
        $this->member = $member;
        $this->game = $game;
        $this->characterName = $characterName;
    }

    public function getId(): ?int { return $this->id; }
    public function getGuild(): Guild { return $this->guild; }
    public function getMember(): GuildMember { return $this->member; }
    public function getGame(): Game { return $this->game; }
    public function getCharacterName(): string { return $this->characterName; }
    public function getCharacterClass(): ?string { return $this->characterClass; }
    public function setCharacterClass(?string $value): self { $value=$value===null?null:trim($value); $this->characterClass=$value===''?null:$value; return $this; }
    public function getRole(): ?string { return $this->role; }
    public function setRole(?string $value): self { $value=$value===null?null:trim($value); $this->role=$value===''?null:$value; return $this; }
    public function isMainCharacter(): bool { return $this->mainCharacter; }
    public function setMainCharacter(bool $main): self { $this->mainCharacter=$main; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active=$active; return $this; }
}

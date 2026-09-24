<?php

declare(strict_types=1);

namespace App\Entity\Guild;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildMember;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'guild_character')]
#[ORM\UniqueConstraint(name: 'uniq_guild_character_identity', columns: ['guild_id', 'game_id', 'character_name'])]
class GuildCharacter
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
    private ?string $characterClass;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $role;

    #[ORM\Column(options: ['default' => false])]
    private bool $mainCharacter;

    public function __construct(Guild $guild, GuildMember $member, Game $game, string $characterName, ?string $characterClass = null, ?string $role = null, bool $mainCharacter = false)
    {
        if ($member->getGuild() !== $guild) {
            throw new \DomainException('Character member must belong to the same guild.');
        }
        if ($guild->getGame() !== null && $game !== $guild->getGame()) {
            throw new \DomainException('Character game must match the guild game.');
        }
        $characterName = trim($characterName);
        if ($characterName === '') {
            throw new \InvalidArgumentException('Character name is required.');
        }
        $this->guild = $guild;
        $this->member = $member;
        $this->game = $game;
        $this->characterName = $characterName;
        $this->characterClass = self::optional($characterClass);
        $this->role = self::optional($role);
        $this->mainCharacter = $mainCharacter;
    }

    public function getId(): ?int { return $this->id; }
    public function getGuild(): Guild { return $this->guild; }
    public function getMember(): GuildMember { return $this->member; }
    public function getGame(): Game { return $this->game; }
    public function getCharacterName(): string { return $this->characterName; }
    public function getCharacterClass(): ?string { return $this->characterClass; }
    public function getRole(): ?string { return $this->role; }
    public function isMainCharacter(): bool { return $this->mainCharacter; }

    private static function optional(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value === '' ? null : $value;
    }
}

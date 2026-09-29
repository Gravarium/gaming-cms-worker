<?php

declare(strict_types=1);

namespace App\Entity\Guild;

use App\Entity\Game;
use App\Entity\Guild;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'guild_role_need')]
#[ORM\UniqueConstraint(name: 'uniq_guild_role_need', columns: ['guild_id', 'game_id', 'role_key', 'class_key'])]
class GuildRoleNeed
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Guild $guild;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Game $game;

    #[ORM\Column(length: 40)]
    private string $roleKey;

    #[ORM\Column(length: 100)]
    private string $classKey;

    #[ORM\Column(options: ['default' => 0])]
    private int $desiredCount = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct(Guild $guild, Game $game, string $roleKey, string $classKey)
    {
        $roleKey=trim($roleKey); $classKey=trim($classKey);
        if ($roleKey==='' || $classKey==='') throw new \InvalidArgumentException('Role and class are required.');
        $this->guild=$guild; $this->game=$game; $this->roleKey=$roleKey; $this->classKey=$classKey;
    }

    public function setDesiredCount(int $count): self { if ($count<0 || $count>1000) throw new \InvalidArgumentException('Invalid desired count.'); $this->desiredCount=$count; return $this; }
    public function getDesiredCount(): int { return $this->desiredCount; }
    public function getGuild(): Guild { return $this->guild; }
    public function getGame(): Game { return $this->game; }
    public function getRoleKey(): string { return $this->roleKey; }
    public function getClassKey(): string { return $this->classKey; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active=$active; return $this; }
}

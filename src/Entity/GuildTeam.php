<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GuildTeamRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GuildTeamRepository::class)]
#[ORM\Table(name: 'guild_team')]
#[ORM\UniqueConstraint(name: 'uniq_guild_team_name', columns: ['guild_id', 'name'])]
class GuildTeam
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Guild $guild = null;

    #[ORM\Column(length: 120)] #[Assert\NotBlank] #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 7, nullable: true)]
    #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/')]
    private ?string $color = null;

    #[ORM\ManyToOne] #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?GuildMember $leader = null;

    /** @var Collection<int, GuildMember> */
    #[ORM\ManyToMany(targetEntity: GuildMember::class)]
    #[ORM\JoinTable(name: 'guild_team_member')]
    private Collection $members;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->members = new ArrayCollection(); $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getGuild(): ?Guild { return $this->guild; }
    public function setGuild(Guild $guild): self
    {
        if ($this->leader !== null && $this->leader->getGuild() !== null && $this->leader->getGuild() !== $guild) { throw new \DomainException('A guild team cannot contain members from another guild.'); }
        foreach ($this->members as $member) {
            if ($member->getGuild() !== null && $member->getGuild() !== $guild) { throw new \DomainException('A guild team cannot contain members from another guild.'); }
        }
        $this->guild = $guild;
        return $this;
    }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self
    {
        if (!mb_check_encoding($name, 'UTF-8') || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Guild team name must be valid UTF-8 without NUL bytes.');
        }

        $normalizedName = trim($name);
        if (strlen($normalizedName) > 480 || mb_strlen($normalizedName, 'UTF-8') > 120) {
            throw new \InvalidArgumentException('Guild team name must fit its 120-character storage column.');
        }

        $this->name = $normalizedName;

        return $this;
    }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $value): self { $value = $value === null ? null : trim($value); $this->description = $value === '' ? null : $value; return $this; }
    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $value): self { $value = $value === null ? null : trim($value); $this->color = $value === '' ? null : $value; return $this; }
    public function getLeader(): ?GuildMember { return $this->leader; }
    public function setLeader(?GuildMember $leader): self { if ($leader !== null) { $this->assertMemberGuild($leader); } $this->leader = $leader; return $this; }
    /** @return Collection<int, GuildMember> */
    public function getMembers(): Collection { return $this->members; }
    public function addMember(GuildMember $member): self { $this->assertMemberGuild($member); if (!$this->members->contains($member)) { $this->members->add($member); } return $this; }
    public function removeMember(GuildMember $member): self { $this->members->removeElement($member); return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function __toString(): string { return $this->name; }

    private function assertMemberGuild(GuildMember $member): void
    {
        if ($this->guild !== null && $member->getGuild() !== null && $member->getGuild() !== $this->guild) {
            throw new \DomainException('A guild team cannot contain members from another guild.');
        }
    }
}

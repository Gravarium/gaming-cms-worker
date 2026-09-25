<?php

declare(strict_types=1);

namespace App\Entity\Guild;

use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'guild_private_member_note')]
#[ORM\Index(name: 'idx_guild_private_note_timeline', columns: ['guild_id','member_id','created_at'])]
class GuildPrivateMemberNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id=null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')]
    private Guild $guild;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')]
    private GuildMember $member;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')]
    private User $author;

    #[ORM\Column(type:Types::TEXT)]
    private string $note;

    #[ORM\Column(type:Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Guild $guild, GuildMember $member, User $author, string $note)
    {
        if ($member->getGuild() !== $guild) throw new \DomainException('Private note guild mismatch.');
        $note=trim($note); if ($note==='' || mb_strlen($note)>5000) throw new \InvalidArgumentException('Invalid private note.');
        $this->guild=$guild; $this->member=$member; $this->author=$author; $this->note=$note; $this->createdAt=new \DateTimeImmutable();
    }

    public function getGuild(): Guild { return $this->guild; }
    public function getMember(): GuildMember { return $this->member; }
    public function getAuthor(): User { return $this->author; }
    public function getNote(): string { return $this->note; }
}

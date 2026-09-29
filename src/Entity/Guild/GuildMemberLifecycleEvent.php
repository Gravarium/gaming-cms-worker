<?php

declare(strict_types=1);

namespace App\Entity\Guild;

use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'guild_member_lifecycle_event')]
#[ORM\Index(name: 'idx_guild_member_lifecycle', columns: ['guild_id','member_id','created_at'])]
class GuildMemberLifecycleEvent
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
    private User $actor;

    #[ORM\Column(length:24)]
    private string $action;

    #[ORM\Column(length:1000)]
    private string $reason;

    #[ORM\Column(type:Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Guild $guild, GuildMember $member, User $actor, string $action, string $reason)
    {
        if ($member->getGuild() !== $guild) throw new \DomainException('Lifecycle event guild mismatch.');
        $allowed=['promote','demote','warn','absence','return','onboard','offboard'];
        $reason=trim($reason);
        if (!in_array($action,$allowed,true) || $reason==='') throw new \InvalidArgumentException('Invalid lifecycle event.');
        $this->guild=$guild; $this->member=$member; $this->actor=$actor; $this->action=$action; $this->reason=$reason; $this->createdAt=new \DateTimeImmutable();
    }

    public function getGuild(): Guild { return $this->guild; }
    public function getMember(): GuildMember { return $this->member; }
    public function getActor(): User { return $this->actor; }
    public function getAction(): string { return $this->action; }
    public function getReason(): string { return $this->reason; }
}

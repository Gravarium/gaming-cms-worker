<?php

declare(strict_types=1);

namespace App\Entity\Guild;

use App\Entity\Guild;
use App\Entity\GuildApplication;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'guild_application_vote')]
#[ORM\UniqueConstraint(name: 'uniq_guild_application_vote', columns: ['application_id', 'voter_id'])]
class GuildApplicationVote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GuildApplication $application;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Guild $guild;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $voter;

    #[ORM\Column(length: 16)]
    private string $decision;

    #[ORM\Column(length: 1000)]
    private string $reason;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(GuildApplication $application, Guild $guild, User $voter, string $decision, string $reason)
    {
        if ($application->getGuild() !== $guild) throw new \DomainException('Application vote guild mismatch.');
        if (!in_array($decision, ['approve','reject','abstain'], true)) throw new \InvalidArgumentException('Unknown vote decision.');
        $reason=trim($reason); if ($reason==='') throw new \InvalidArgumentException('Vote reason is required.');
        $this->application=$application; $this->guild=$guild; $this->voter=$voter; $this->decision=$decision; $this->reason=$reason; $this->createdAt=new \DateTimeImmutable();
    }

    public function getDecision(): string { return $this->decision; }
    public function getReason(): string { return $this->reason; }
    public function getGuild(): Guild { return $this->guild; }
    public function getApplication(): GuildApplication { return $this->application; }
    public function getVoter(): User { return $this->voter; }
}

<?php

declare(strict_types=1);

namespace App\Entity\Guild;

use App\Entity\Guild;
use App\Entity\GuildApplication;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name:'guild_recruitment_case')]
#[ORM\UniqueConstraint(name:'uniq_guild_recruitment_application',columns:['application_id'])]
class GuildRecruitmentCase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id=null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')]
    private GuildApplication $application;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')]
    private Guild $guild;

    #[ORM\Column(length:16)]
    private string $status='submitted';

    #[ORM\Column(length:1000,nullable:true)]
    private ?string $decisionReason=null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete:'SET NULL')]
    private ?User $decidedBy=null;

    #[ORM\Column(type:Types::DATETIME_IMMUTABLE,nullable:true)]
    private ?\DateTimeImmutable $trialExpiresAt=null;

    #[ORM\Column(type:Types::TEXT,nullable:true)]
    private ?string $appealMessage=null;

    #[ORM\Column(type:Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(GuildApplication $application,Guild $guild)
    {
        if($application->getGuild()!==$guild) throw new \DomainException('Recruitment case guild mismatch.');
        $this->application=$application;$this->guild=$guild;$this->updatedAt=new \DateTimeImmutable();
    }

    public function startReview():void { if($this->status!=='submitted') throw new \DomainException('Only submitted cases can enter review.'); $this->status='review';$this->touch(); }
    public function startTrial(\DateTimeImmutable $expiresAt):void { if($this->status!=='review'||$expiresAt<=new \DateTimeImmutable()) throw new \DomainException('Trial requires review and future expiry.');$this->status='trial';$this->trialExpiresAt=$expiresAt;$this->touch(); }
    public function decide(bool $accepted,User $actor,string $reason):void { $reason=trim($reason);if(!in_array($this->status,['review','trial'],true)||$reason==='') throw new \DomainException('Decision requires active review or trial and reason.');$this->status=$accepted?'accepted':'rejected';$this->decidedBy=$actor;$this->decisionReason=$reason;$this->touch(); }
    public function expire(\DateTimeImmutable $now):void { if($this->status!=='trial'||$this->trialExpiresAt===null||$this->trialExpiresAt>$now) throw new \DomainException('Trial is not due.');$this->status='expired';$this->touch(); }
    public function appeal(string $message):void { $message=trim($message);if(!in_array($this->status,['rejected','expired'],true)||$message===''||$this->appealMessage!==null) throw new \DomainException('Appeal not allowed.');$this->appealMessage=$message;$this->touch(); }
    public function getStatus():string{return $this->status;}
    public function getGuild():Guild{return $this->guild;}
    public function getApplication():GuildApplication{return $this->application;}
    public function getDecisionReason():?string{return $this->decisionReason;}
    public function getTrialExpiresAt():?\DateTimeImmutable{return $this->trialExpiresAt;}
    public function getAppealMessage():?string{return $this->appealMessage;}
    private function touch():void{$this->updatedAt=new \DateTimeImmutable();}
}

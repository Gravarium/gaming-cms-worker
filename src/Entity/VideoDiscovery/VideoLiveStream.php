<?php
declare(strict_types=1);
namespace App\Entity\VideoDiscovery;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name:'video_discovery_live_stream')]
class VideoLiveStream
{
    public const PROVIDERS=['youtube','twitch','vimeo'];
    public const CONSENT_CLICK='click_to_load';
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(onDelete:'SET NULL')] private ?CreatorProfile $creator=null;
    #[ORM\Column(length:160)] private string $title;
    #[ORM\Column(length:16)] private string $provider;
    #[ORM\Column(length:700)] private string $sourceUrl;
    #[ORM\Column(length:24)] private string $consentMode=self::CONSENT_CLICK;
    #[ORM\Column(options:['default'=>true])] private bool $enabled=true;
    #[ORM\Column(type:Types::DATETIME_IMMUTABLE,nullable:true)] private ?\DateTimeImmutable $startsAt=null;
    public function __construct(string $title,string $provider,string $sourceUrl){$title=trim($title);if($title===''||!in_array($provider,self::PROVIDERS,true))throw new \InvalidArgumentException('Invalid live stream.');$this->title=$title;$this->provider=$provider;$this->sourceUrl=trim($sourceUrl);}
    public function getId():?int{return $this->id;} public function getTitle():string{return $this->title;} public function getProvider():string{return $this->provider;} public function getSourceUrl():string{return $this->sourceUrl;} public function getConsentMode():string{return $this->consentMode;} public function isEnabled():bool{return $this->enabled;}
    public function setCreator(?CreatorProfile $creator):self{$this->creator=$creator;return $this;} public function getCreator():?CreatorProfile{return $this->creator;} public function setEnabled(bool $enabled):self{$this->enabled=$enabled;return $this;} public function setStartsAt(?\DateTimeImmutable $at):self{$this->startsAt=$at;return $this;} public function getStartsAt():?\DateTimeImmutable{return $this->startsAt;}
}

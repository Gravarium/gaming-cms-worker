<?php
declare(strict_types=1);
namespace App\Entity\Download;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name:'download_version')]
#[ORM\UniqueConstraint(name:'uniq_download_package_version',columns:['package_id','version'])]
class DownloadVersion
{
    public const SCAN=['pending','clean','rejected','unavailable'];
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private DownloadPackage $package;
    #[ORM\ManyToOne(targetEntity:self::class)] #[ORM\JoinColumn(onDelete:'SET NULL')] private ?self $replacedBy=null;
    #[ORM\Column(length:80)] private string $version;
    #[ORM\Column(length:255)] private string $safeFilename;
    #[ORM\Column(length:64)] private string $sha256;
    #[ORM\Column(length:20)] private string $scanStatus='pending';
    #[ORM\Column(length:500)] private string $storageReference;
    /** @var list<string> */
    #[ORM\Column(type:Types::JSON)] private array $compatibility=[];
    #[ORM\Column(type:Types::TEXT,nullable:true)] private ?string $changelog=null;
    #[ORM\Column(options:['default'=>false])] private bool $obsolete=false;
    public function __construct(DownloadPackage $package,string $version,string $safeFilename,string $sha256,string $storageReference){$version=trim($version);if($version===''||!preg_match('/^[A-Za-z0-9._+-]{1,80}$/D',$version))throw new \InvalidArgumentException('Invalid version.');if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/D',$safeFilename))throw new \InvalidArgumentException('Unsafe filename.');if(!preg_match('/^[a-f0-9]{64}$/D',$sha256))throw new \InvalidArgumentException('Invalid SHA-256.');if($storageReference===''||str_starts_with($storageReference,'/')||str_contains($storageReference,'..'))throw new \InvalidArgumentException('Invalid private storage reference.');$this->package=$package;$this->version=$version;$this->safeFilename=$safeFilename;$this->sha256=$sha256;$this->storageReference=$storageReference;}
    public function getId():?int{return $this->id;} public function getPackage():DownloadPackage{return $this->package;} public function getVersion():string{return $this->version;} public function getSafeFilename():string{return $this->safeFilename;} public function getSha256():string{return $this->sha256;} public function getStorageReference():string{return $this->storageReference;} public function getScanStatus():string{return $this->scanStatus;} public function isObsolete():bool{return $this->obsolete;} public function getReplacedBy():?self{return $this->replacedBy;}
    /** @param list<string> $compatibility */ public function setCompatibility(array $compatibility):self{$this->compatibility=array_values(array_unique(array_map('trim',$compatibility)));return $this;} /** @return list<string> */ public function getCompatibility():array{return $this->compatibility;}
    public function setChangelog(?string $value):self{$value=$value===null?null:trim($value);$this->changelog=$value===''?null:$value;return $this;} public function getChangelog():?string{return $this->changelog;}
    public function markScan(string $status):self{if(!in_array($status,self::SCAN,true))throw new \InvalidArgumentException('Invalid scan status.');$this->scanStatus=$status;return $this;}
    public function replaceWith(self $replacement):void{if($replacement->package!==$this->package)throw new \DomainException('Replacement must belong to same package.');$this->obsolete=true;$this->replacedBy=$replacement;}
    public function isDeliverable():bool{return !$this->obsolete&&$this->scanStatus==='clean'&&$this->package->isEnabled();}
}

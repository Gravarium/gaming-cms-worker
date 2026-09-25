<?php
declare(strict_types=1);
namespace App\Entity\GameCatalogue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity(repositoryClass:\App\Repository\GameCatalogue\GameReleaseRepository::class)]
#[ORM\Table(name:'game_catalogue_release')]
#[ORM\Index(name:'idx_game_catalogue_release_date',columns:['release_at','region'])]
class GameRelease
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private GameCatalogueEntry $entry;
    #[ORM\ManyToOne] #[ORM\JoinColumn(onDelete:'SET NULL')] private ?GameEdition $edition=null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')] private GamePlatform $platform;
    #[ORM\Column(length:60)] private string $region;
    #[ORM\Column(type:Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $releaseAt;
    #[ORM\Column(length:20)] private string $status='announced';
    public function __construct(GameCatalogueEntry $entry,GamePlatform $platform,string $region,\DateTimeImmutable $releaseAt){$region=trim($region);if($region==='')throw new \InvalidArgumentException('Release region is required.');$this->entry=$entry;$this->platform=$platform;$this->region=$region;$this->releaseAt=$releaseAt;}
    public function getId():?int{return $this->id;} public function getEntry():GameCatalogueEntry{return $this->entry;} public function getPlatform():GamePlatform{return $this->platform;} public function getRegion():string{return $this->region;} public function getReleaseAt():\DateTimeImmutable{return $this->releaseAt;}
    public function getEdition():?GameEdition{return $this->edition;} public function setEdition(?GameEdition $edition):self{if($edition!==null&&$edition->getEntry()!==$this->entry)throw new \DomainException('Edition must belong to the same catalogue entry.');$this->edition=$edition;return $this;}
    public function getStatus():string{return $this->status;} public function setStatus(string $status):self{if(!in_array($status,['announced','released','delayed','cancelled'],true))throw new \InvalidArgumentException('Unknown release status.');$this->status=$status;return $this;}
}

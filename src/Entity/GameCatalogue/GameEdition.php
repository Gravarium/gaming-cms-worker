<?php
declare(strict_types=1);
namespace App\Entity\GameCatalogue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name:'game_catalogue_edition')]
#[ORM\UniqueConstraint(name:'uniq_game_catalogue_edition',columns:['entry_id','name'])]
class GameEdition
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private GameCatalogueEntry $entry;
    #[ORM\Column(length:140)] private string $name;
    #[ORM\Column(type:Types::TEXT,nullable:true)] private ?string $systemRequirements=null;
    public function __construct(GameCatalogueEntry $entry,string $name){$name=trim($name);if($name==='')throw new \InvalidArgumentException('Edition name is required.');$this->entry=$entry;$this->name=$name;}
    public function getId():?int{return $this->id;} public function getEntry():GameCatalogueEntry{return $this->entry;} public function getName():string{return $this->name;}
    public function getSystemRequirements():?string{return $this->systemRequirements;} public function setSystemRequirements(?string $value):self{$value=$value===null?null:trim($value);$this->systemRequirements=$value===''?null:$value;return $this;}
}

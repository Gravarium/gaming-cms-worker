<?php
declare(strict_types=1);
namespace App\Entity\GameCatalogue;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name:'game_catalogue_hub_link')]
#[ORM\Index(name:'idx_game_catalogue_hub_target',columns:['target_type','target_id'])]
class GameHubLink
{
    public const TYPES=['content','guild','video','server'];
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private GameCatalogueEntry $entry;
    #[ORM\Column(length:20)] private string $targetType;
    #[ORM\Column] private int $targetId;
    #[ORM\Column(length:180)] private string $label;
    public function __construct(GameCatalogueEntry $entry,string $targetType,int $targetId,string $label){$label=trim($label);if(!in_array($targetType,self::TYPES,true)||$targetId<1||$label==='')throw new \InvalidArgumentException('Invalid game hub link.');$this->entry=$entry;$this->targetType=$targetType;$this->targetId=$targetId;$this->label=$label;}
    public function getEntry():GameCatalogueEntry{return $this->entry;} public function getTargetType():string{return $this->targetType;} public function getTargetId():int{return $this->targetId;} public function getLabel():string{return $this->label;}
}

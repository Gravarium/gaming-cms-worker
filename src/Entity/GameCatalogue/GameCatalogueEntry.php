<?php
declare(strict_types=1);
namespace App\Entity\GameCatalogue;
use App\Entity\Game;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity(repositoryClass:\App\Repository\GameCatalogue\GameCatalogueEntryRepository::class)]
#[ORM\Table(name:'game_catalogue_entry')]
#[ORM\UniqueConstraint(name:'uniq_game_catalogue_game',columns:['game_id'])]
class GameCatalogueEntry
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private Game $game;
    #[ORM\ManyToOne] #[ORM\JoinColumn(onDelete:'SET NULL')] private ?GamePublisher $publisher=null;
    #[ORM\Column(type:Types::TEXT,nullable:true)] private ?string $summary=null;
    #[ORM\Column(length:160,nullable:true)] private ?string $developer=null;
    #[ORM\Column(options:['default'=>true])] private bool $enabled=true;
    /** @var Collection<int,GameGenre> */
    #[ORM\ManyToMany(targetEntity:GameGenre::class)]
    #[ORM\JoinTable(name:'game_catalogue_entry_genre')] private Collection $genres;
    public function __construct(Game $game){$this->game=$game;$this->genres=new ArrayCollection();}
    public function getId():?int{return $this->id;} public function getGame():Game{return $this->game;} public function getPublisher():?GamePublisher{return $this->publisher;}
    public function setPublisher(?GamePublisher $publisher):self{$this->publisher=$publisher;return $this;}
    public function getSummary():?string{return $this->summary;} public function setSummary(?string $summary):self{$summary=$summary===null?null:trim($summary);$this->summary=$summary===''?null:$summary;return $this;}
    public function getDeveloper():?string{return $this->developer;} public function setDeveloper(?string $developer):self{$developer=$developer===null?null:trim($developer);$this->developer=$developer===''?null:$developer;return $this;}
    public function isEnabled():bool{return $this->enabled;} public function setEnabled(bool $enabled):self{$this->enabled=$enabled;return $this;}
    /** @return Collection<int,GameGenre> */ public function getGenres():Collection{return $this->genres;}
    public function addGenre(GameGenre $genre):self{if(!$this->genres->contains($genre))$this->genres->add($genre);return $this;}
    public function isPublic():bool{return $this->enabled&&$this->game->isEnabled();}
}

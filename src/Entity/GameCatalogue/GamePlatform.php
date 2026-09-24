<?php
declare(strict_types=1);
namespace App\Entity\GameCatalogue;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name:'game_catalogue_platform')]
#[ORM\UniqueConstraint(name:'uniq_game_catalogue_platform_slug',columns:['slug'])]
class GamePlatform
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\Column(length:120)] private string $name;
    #[ORM\Column(length:140)] private string $slug;
    public function __construct(string $name,string $slug){$name=trim($name);$slug=trim($slug);if($name===''||$slug==='')throw new \InvalidArgumentException('Platform name and slug are required.');$this->name=$name;$this->slug=$slug;}
    public function getId():?int{return $this->id;} public function getName():string{return $this->name;} public function getSlug():string{return $this->slug;}
}

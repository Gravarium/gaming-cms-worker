<?php
declare(strict_types=1);
namespace App\Entity\GameCatalogue;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name:'game_catalogue_publisher')]
#[ORM\UniqueConstraint(name:'uniq_game_catalogue_publisher_slug',columns:['slug'])]
class GamePublisher
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\Column(length:160)] private string $name;
    #[ORM\Column(length:180)] private string $slug;
    public function __construct(string $name,string $slug){$name=trim($name);$slug=trim($slug);if($name===''||$slug==='')throw new \InvalidArgumentException('Publisher name and slug are required.');$this->name=$name;$this->slug=$slug;}
    public function getId():?int{return $this->id;} public function getName():string{return $this->name;} public function getSlug():string{return $this->slug;}
}

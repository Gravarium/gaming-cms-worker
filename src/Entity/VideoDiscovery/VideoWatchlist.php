<?php
declare(strict_types=1);
namespace App\Entity\VideoDiscovery;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name:'video_discovery_watchlist')]
#[ORM\UniqueConstraint(name:'uniq_video_watchlist_user_name',columns:['user_id','name'])]
class VideoWatchlist
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private User $user;
    #[ORM\Column(length:120)] private string $name;
    #[ORM\Column(options:['default'=>false])] private bool $public=false;
    #[ORM\Column(type:Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt;
    public function __construct(User $user,string $name){$name=trim($name);if($name===''||mb_strlen($name)>120)throw new \InvalidArgumentException('Invalid watchlist name.');$this->user=$user;$this->name=$name;$this->createdAt=new \DateTimeImmutable();}
    public function getId():?int{return $this->id;} public function getUser():User{return $this->user;} public function getName():string{return $this->name;} public function isPublic():bool{return $this->public;} public function setPublic(bool $public):self{$this->public=$public;return $this;}
}

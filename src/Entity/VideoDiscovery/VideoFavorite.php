<?php
declare(strict_types=1);
namespace App\Entity\VideoDiscovery;
use App\Entity\User;
use App\Entity\Video;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name:'video_discovery_favorite')]
#[ORM\UniqueConstraint(name:'uniq_video_favorite_user_video',columns:['user_id','video_id'])]
class VideoFavorite
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private User $user;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private Video $video;
    #[ORM\Column(type:Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt;
    public function __construct(User $user,Video $video){$this->user=$user;$this->video=$video;$this->createdAt=new \DateTimeImmutable();}
    public function getUser():User{return $this->user;} public function getVideo():Video{return $this->video;}
}

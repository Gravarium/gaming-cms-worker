<?php
declare(strict_types=1);
namespace App\Entity\VideoDiscovery;
use App\Entity\User;
use App\Entity\Video;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name:'video_discovery_timestamp_comment')]
#[ORM\Index(name:'idx_video_timestamp_comment_video',columns:['video_id','timestamp_seconds','created_at'])]
class VideoTimestampComment
{
    public const VISIBILITIES=['public','member'];
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private User $author;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private Video $video;
    #[ORM\Column] private int $timestampSeconds;
    #[ORM\Column(type:Types::TEXT)] private string $body;
    #[ORM\Column(length:16)] private string $visibility='public';
    #[ORM\Column(type:Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt;
    public function __construct(User $author,Video $video,int $timestampSeconds,string $body){$body=trim($body);if($timestampSeconds<0||$body===''||mb_strlen($body)>4000)throw new \InvalidArgumentException('Invalid timestamp comment.');$this->author=$author;$this->video=$video;$this->timestampSeconds=$timestampSeconds;$this->body=$body;$this->createdAt=new \DateTimeImmutable();}
    public function getAuthor():User{return $this->author;} public function getVideo():Video{return $this->video;} public function getTimestampSeconds():int{return $this->timestampSeconds;} public function getBody():string{return $this->body;} public function getVisibility():string{return $this->visibility;}
    public function setVisibility(string $visibility):self{if(!in_array($visibility,self::VISIBILITIES,true))throw new \InvalidArgumentException('Invalid comment visibility.');$this->visibility=$visibility;return $this;}
}

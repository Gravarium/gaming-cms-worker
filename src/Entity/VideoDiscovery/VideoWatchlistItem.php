<?php
declare(strict_types=1);
namespace App\Entity\VideoDiscovery;
use App\Entity\Video;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name:'video_discovery_watchlist_item')]
#[ORM\UniqueConstraint(name:'uniq_video_watchlist_item',columns:['watchlist_id','video_id'])]
class VideoWatchlistItem
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private VideoWatchlist $watchlist;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private Video $video;
    #[ORM\Column(type:Types::DATETIME_IMMUTABLE)] private \DateTimeImmutable $createdAt;
    public function __construct(VideoWatchlist $watchlist,Video $video){$this->watchlist=$watchlist;$this->video=$video;$this->createdAt=new \DateTimeImmutable();}
    public function getWatchlist():VideoWatchlist{return $this->watchlist;} public function getVideo():Video{return $this->video;}
}

<?php
declare(strict_types=1);
namespace App\Entity\VideoDiscovery;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name:'video_discovery_history_preference')]
class VideoHistoryPreference
{
    #[ORM\Id] #[ORM\OneToOne] #[ORM\JoinColumn(name:'user_id',referencedColumnName:'id',nullable:false,onDelete:'CASCADE')] private User $user;
    #[ORM\Column(options:['default'=>false])] private bool $enabled=false;
    public function __construct(User $user){$this->user=$user;} public function getUser():User{return $this->user;} public function isEnabled():bool{return $this->enabled;} public function setEnabled(bool $enabled):self{$this->enabled=$enabled;return $this;}
}

<?php

declare(strict_types=1);

namespace App\Entity\VideoDiscovery;

use App\Entity\Video;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\VideoDiscovery\VideoDiscoveryProfileRepository::class)]
#[ORM\Table(name:'video_discovery_profile')]
#[ORM\UniqueConstraint(name:'uniq_video_discovery_video',columns:['video_id'])]
class VideoDiscoveryProfile
{
    public const VISIBILITY_PUBLIC='public';
    public const VISIBILITY_MEMBER='member';
    public const VISIBILITY_PRIVATE='private';

    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id=null;
    #[ORM\OneToOne] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private Video $video;
    #[ORM\ManyToOne] #[ORM\JoinColumn(onDelete:'SET NULL')] private ?CreatorProfile $creator=null;
    #[ORM\Column(length:16)] private string $visibility=self::VISIBILITY_PUBLIC;
    #[ORM\Column(options:['default'=>true])] private bool $discoverable=true;
    /** @var Collection<int,VideoTag> */
    #[ORM\ManyToMany(targetEntity:VideoTag::class)]
    #[ORM\JoinTable(name:'video_discovery_profile_tag')]
    #[ORM\JoinColumn(name:'profile_id', referencedColumnName:'id', onDelete:'CASCADE')]
    #[ORM\InverseJoinColumn(name:'tag_id', referencedColumnName:'id', onDelete:'CASCADE')]
    private Collection $tags;

    public function __construct(Video $video){$this->video=$video;$this->tags=new ArrayCollection();}
    public function getId():?int{return $this->id;}
    public function getVideo():Video{return $this->video;}
    public function getCreator():?CreatorProfile{return $this->creator;}
    public function setCreator(?CreatorProfile $creator):self{$this->creator=$creator;return $this;}
    public function getVisibility():string{return $this->visibility;}
    public function setVisibility(string $visibility):self{if(!in_array($visibility,[self::VISIBILITY_PUBLIC,self::VISIBILITY_MEMBER,self::VISIBILITY_PRIVATE],true))throw new \InvalidArgumentException('Invalid video visibility.');$this->visibility=$visibility;return $this;}
    public function isDiscoverable():bool{return $this->discoverable;}
    public function setDiscoverable(bool $discoverable):self{$this->discoverable=$discoverable;return $this;}
    /** @return Collection<int,VideoTag> */ public function getTags():Collection{return $this->tags;}
    public function addTag(VideoTag $tag):self{if(!$this->tags->contains($tag))$this->tags->add($tag);return $this;}
}

<?php

declare(strict_types=1);

namespace App\Entity\VideoDiscovery;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'video_discovery_tag')]
#[ORM\UniqueConstraint(name: 'uniq_video_discovery_tag_slug', columns: ['slug'])]
class VideoTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id=null;

    #[ORM\Column(length:100)]
    private string $name;

    #[ORM\Column(length:120)]
    private string $slug;

    public function __construct(string $name,string $slug)
    {
        $name=trim($name);$slug=trim($slug);
        if($name===''||preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug)!==1)throw new \InvalidArgumentException('Invalid video tag.');
        $this->name=$name;$this->slug=$slug;
    }
    public function getId():?int{return $this->id;}
    public function getName():string{return $this->name;}
    public function getSlug():string{return $this->slug;}
}

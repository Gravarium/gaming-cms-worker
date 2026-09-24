<?php

declare(strict_types=1);

namespace App\Entity\VideoDiscovery;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'video_discovery_creator')]
#[ORM\UniqueConstraint(name: 'uniq_video_creator_slug', columns: ['slug'])]
class CreatorProfile
{
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_MEMBER = 'member';
    public const VISIBILITY_PRIVATE = 'private';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $owner = null;

    #[ORM\Column(length: 160)]
    private string $displayName;

    #[ORM\Column(length: 180)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(length: 16)]
    private string $visibility = self::VISIBILITY_PUBLIC;

    public function __construct(string $displayName, string $slug)
    {
        $displayName = trim($displayName);
        $slug = trim($slug);
        if ($displayName === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            throw new \InvalidArgumentException('Invalid creator profile.');
        }
        $this->displayName = $displayName;
        $this->slug = $slug;
    }

    public function getId(): ?int { return $this->id; }
    public function getDisplayName(): string { return $this->displayName; }
    public function getSlug(): string { return $this->slug; }
    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(?User $owner): self { $this->owner = $owner; return $this; }
    public function getBio(): ?string { return $this->bio; }
    public function setBio(?string $bio): self { $bio=$bio===null?null:trim($bio); $this->bio=$bio===''?null:$bio; return $this; }
    public function getVisibility(): string { return $this->visibility; }
    public function setVisibility(string $visibility): self
    {
        if (!in_array($visibility, [self::VISIBILITY_PUBLIC,self::VISIBILITY_MEMBER,self::VISIBILITY_PRIVATE], true)) {
            throw new \InvalidArgumentException('Invalid creator visibility.');
        }
        $this->visibility = $visibility;
        return $this;
    }
}

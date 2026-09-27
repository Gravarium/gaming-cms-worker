<?php

declare(strict_types=1);

namespace App\Entity\VideoDiscovery;

use App\Entity\User;
use App\Entity\Video;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'video_discovery_clip')]
#[ORM\UniqueConstraint(name: 'uniq_video_clip_slug', columns: ['slug'])]
class VideoClip
{
    public const VISIBILITIES = ['public', 'member', 'private'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Video $video;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[ORM\Column(length: 160)]
    private string $title;

    #[ORM\Column(length: 180)]
    private string $slug;

    #[ORM\Column]
    private int $startSeconds;

    #[ORM\Column]
    private int $endSeconds;

    #[ORM\Column(length: 16)]
    private string $visibility = 'public';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Video $video,
        User $createdBy,
        string $title,
        string $slug,
        int $startSeconds,
        int $endSeconds,
    ) {
        $title = trim($title);
        $slug = trim($slug);
        if ($title === ''
            || mb_strlen($title) > 160
            || mb_strlen($slug) > 180
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1
            || $startSeconds < 0
            || $endSeconds <= $startSeconds
            || $endSeconds - $startSeconds > 600
        ) {
            throw new \InvalidArgumentException('Invalid video clip.');
        }

        $this->video = $video;
        $this->createdBy = $createdBy;
        $this->title = $title;
        $this->slug = $slug;
        $this->startSeconds = $startSeconds;
        $this->endSeconds = $endSeconds;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getVideo(): Video { return $this->video; }
    public function getCreatedBy(): User { return $this->createdBy; }
    public function getTitle(): string { return $this->title; }
    public function getSlug(): string { return $this->slug; }
    public function getStartSeconds(): int { return $this->startSeconds; }
    public function getEndSeconds(): int { return $this->endSeconds; }
    public function getVisibility(): string { return $this->visibility; }

    public function setVisibility(string $visibility): self
    {
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            throw new \InvalidArgumentException('Invalid clip visibility.');
        }

        $this->visibility = $visibility;

        return $this;
    }
}

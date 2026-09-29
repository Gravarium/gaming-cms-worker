<?php

declare(strict_types=1);

namespace App\Entity\VideoDiscovery;

use App\Entity\User;
use App\Entity\Video;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'video_discovery_history')]
#[ORM\Index(name: 'idx_video_history_user_time', columns: ['user_id', 'watched_at'])]
class VideoHistoryEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Video $video;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $watchedAt;

    #[ORM\Column(options: ['default' => 0])]
    private int $positionSeconds = 0;

    public function __construct(User $user, Video $video, int $positionSeconds = 0)
    {
        if ($positionSeconds < 0) {
            throw new \InvalidArgumentException('Position must be non-negative.');
        }

        $this->user = $user;
        $this->video = $video;
        $this->positionSeconds = $positionSeconds;
        $this->watchedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getVideo(): Video { return $this->video; }
    public function getWatchedAt(): \DateTimeImmutable { return $this->watchedAt; }
    public function getPositionSeconds(): int { return $this->positionSeconds; }
}

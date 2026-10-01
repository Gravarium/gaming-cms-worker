<?php

declare(strict_types=1);

namespace App\Entity\VideoWorkspace;

use App\Entity\Video;
use App\Entity\VideoDiscovery\CreatorProfile;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'video_workspace_source')]
#[ORM\Index(name: 'idx_vworkspace_video_position', columns: ['video_id', 'position', 'id'])]
class VideoSource
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'CASCADE')] private ?Video $video = null;
    #[ORM\ManyToOne, ORM\JoinColumn(onDelete: 'RESTRICT')] private ?CreatorProfile $creator = null;
    #[ORM\Column(length: 160)] private string $label = '';
    #[ORM\Column(length: 32)] private string $provider = '';
    #[ORM\Column(length: 700)] private string $url = '';
    #[ORM\Column] private bool $enabled = true;
    #[ORM\Column] private int $position = 0;
    #[ORM\Column] private bool $authorized = false;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Version, ORM\Column] private int $version = 1;
    public function getVersion(): int { return $this->version; }

    public function getId(): ?int { return $this->id; }
    public function getVideo(): ?Video { return $this->video; }
    public function setVideo(?Video $video): self { $this->video = $video; return $this; }
    public function getCreator(): ?CreatorProfile { return $this->creator; }
    public function setCreator(?CreatorProfile $creator): self { $this->creator = $creator; return $this; }
    public function getLabel(): string { return $this->label; }
    public function getProvider(): string { return $this->provider; }
    public function getUrl(): string { return $this->url; }
    public function isEnabled(): bool { return $this->enabled; }
    public function getPosition(): int { return $this->position; }
    public function isAuthorized(): bool { return $this->authorized; }
    public function getStartsAt(): ?\DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(?\DateTimeImmutable $at): self { $this->startsAt = $at; return $this; }
    public function configure(string $label, string $provider, string $url, int $position, bool $enabled, bool $authorized): void
    {
        if (trim($label) === '' || mb_strlen($label) > 160 || mb_strlen($url) > 700
            || preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/', $provider) !== 1 || $position < 0 || $position > 10000 || !$authorized) {
            throw new \InvalidArgumentException('Ungültige oder nicht freigegebene Videoquelle.');
        }
        $this->label = trim($label); $this->provider = $provider; $this->url = trim($url);
        $this->position = $position; $this->enabled = $enabled; $this->authorized = $authorized;
    }
}

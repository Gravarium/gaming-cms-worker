<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VideoPlaylistRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: VideoPlaylistRepository::class)]
#[ORM\Table(name: 'video_playlist')]
#[ORM\UniqueConstraint(name: 'uniq_video_playlist_slug', columns: ['slug'])]
#[UniqueEntity(fields: ['slug'])]
class VideoPlaylist
{
    private const MAX_RAW_SLUG_BYTES = 512;
    private const MAX_SLUG_LENGTH = 180;
    private const SLUG_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 160)]
    private string $title = '';

    #[ORM\Column(length: 180)]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    /** @var Collection<int, Video> */
    #[ORM\ManyToMany(targetEntity: Video::class, mappedBy: 'playlists')]
    private Collection $videos;

    public function __construct() { $this->videos = new ArrayCollection(); }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = trim($title); return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self
    {
        $this->slug = $this->normalizeSlug($slug);

        return $this;
    }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description === null || trim($description) === '' ? null : trim($description); return $this; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }
    /** @return Collection<int, Video> */
    public function getVideos(): Collection { return $this->videos; }
    public function __toString(): string { return $this->title; }

    private function normalizeSlug(string $slug): string
    {
        if (strlen($slug) > self::MAX_RAW_SLUG_BYTES || !mb_check_encoding($slug, 'UTF-8')) {
            throw new \InvalidArgumentException('Der Playlist-Slug ist ungültig oder überschreitet die zulässige Länge.');
        }

        $normalized = trim(mb_strtolower($slug, 'UTF-8'));
        if ($normalized === ''
            || strlen($normalized) > self::MAX_SLUG_LENGTH
            || preg_match(self::SLUG_PATTERN, $normalized) !== 1
        ) {
            throw new \InvalidArgumentException('Der Playlist-Slug ist ungültig oder überschreitet die zulässige Länge.');
        }

        return $normalized;
    }
}

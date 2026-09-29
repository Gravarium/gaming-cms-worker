<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VideoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: VideoRepository::class)]
#[ORM\Table(name: 'video')]
#[ORM\UniqueConstraint(name: 'uniq_video_slug', columns: ['slug'])]
#[UniqueEntity(fields: ['slug'])]
class Video
{
    private const MAX_RAW_SLUG_BYTES = 512;
    private const MAX_SLUG_LENGTH = 200;
    private const SLUG_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_YOUTUBE = 'youtube';
    public const SOURCE_VIMEO = 'vimeo';
    public const SOURCE_TWITCH = 'twitch';
    public const SOURCE_EXTERNAL = 'external';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VideoCategory::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?VideoCategory $category = null;

    #[ORM\ManyToOne(targetEntity: MediaAsset::class)]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?MediaAsset $mediaAsset = null;

    /** @var Collection<int, VideoPlaylist> */
    #[ORM\ManyToMany(targetEntity: VideoPlaylist::class, inversedBy: 'videos')]
    #[ORM\JoinTable(name: 'video_video_playlist')]
    private Collection $playlists;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $title = '';

    #[ORM\Column(length: 200)]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $description = '';

    #[ORM\Column(length: 12)]
    #[Assert\Choice(choices: [self::SOURCE_UPLOAD, self::SOURCE_YOUTUBE, self::SOURCE_VIMEO, self::SOURCE_TWITCH, self::SOURCE_EXTERNAL])]
    private string $sourceType = self::SOURCE_UPLOAD;

    #[ORM\Column(length: 700, nullable: true)]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
    #[Assert\Length(max: 700)]
    private ?string $sourceUrl = null;

    #[ORM\Column(length: 700, nullable: true)]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
    #[Assert\Length(max: 700)]
    private ?string $thumbnailUrl = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $durationLabel = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $featured = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->playlists = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getCategory(): ?VideoCategory { return $this->category; }
    public function setCategory(?VideoCategory $category): self { $this->category = $category; return $this; }
    public function getMediaAsset(): ?MediaAsset { return $this->mediaAsset; }
    public function setMediaAsset(?MediaAsset $mediaAsset): self
    {
        if ($mediaAsset?->isDeletionPending()) {
            throw new \DomainException('A video cannot reference media that is pending deletion.');
        }
        $this->mediaAsset = $mediaAsset;

        return $this;
    }
    /** @return Collection<int, VideoPlaylist> */
    public function getPlaylists(): Collection { return $this->playlists; }
    public function addPlaylist(VideoPlaylist $playlist): self { if (!$this->playlists->contains($playlist)) { $this->playlists->add($playlist); } return $this; }
    public function removePlaylist(VideoPlaylist $playlist): self { $this->playlists->removeElement($playlist); return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = trim($title); return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self
    {
        $this->slug = $this->normalizeSlug($slug);

        return $this;
    }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = trim($description); return $this; }
    public function getSourceType(): string { return $this->sourceType; }
    public function setSourceType(string $sourceType): self { $this->sourceType = $sourceType; return $this; }
    public function getSourceUrl(): ?string { return $this->sourceUrl; }
    public function setSourceUrl(?string $sourceUrl): self { $this->sourceUrl = $sourceUrl === null || trim($sourceUrl) === '' ? null : trim($sourceUrl); return $this; }
    public function getThumbnailUrl(): ?string { return $this->thumbnailUrl; }
    public function setThumbnailUrl(?string $thumbnailUrl): self { $this->thumbnailUrl = $thumbnailUrl === null || trim($thumbnailUrl) === '' ? null : trim($thumbnailUrl); return $this; }
    public function getDurationLabel(): ?string { return $this->durationLabel; }
    public function setDurationLabel(?string $durationLabel): self { $this->durationLabel = $durationLabel === null || trim($durationLabel) === '' ? null : trim($durationLabel); return $this; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeImmutable $publishedAt): self { $this->publishedAt = $publishedAt; return $this; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }
    public function isFeatured(): bool { return $this->featured; }
    public function setFeatured(bool $featured): self { $this->featured = $featured; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function isPublished(): bool { return $this->enabled && $this->publishedAt !== null && $this->publishedAt <= new \DateTimeImmutable(); }
    public function getPlaybackUrl(): ?string { return $this->sourceType === self::SOURCE_UPLOAD ? $this->mediaAsset?->getLocation() : $this->sourceUrl; }

    private function normalizeSlug(string $slug): string
    {
        if (strlen($slug) > self::MAX_RAW_SLUG_BYTES || !mb_check_encoding($slug, 'UTF-8')) {
            throw new \InvalidArgumentException('Der Video-Slug ist ungültig oder überschreitet die zulässige Länge.');
        }

        $normalized = trim(mb_strtolower($slug, 'UTF-8'));
        if ($normalized === ''
            || strlen($normalized) > self::MAX_SLUG_LENGTH
            || preg_match(self::SLUG_PATTERN, $normalized) !== 1
        ) {
            throw new \InvalidArgumentException('Der Video-Slug ist ungültig oder überschreitet die zulässige Länge.');
        }

        return $normalized;
    }
}

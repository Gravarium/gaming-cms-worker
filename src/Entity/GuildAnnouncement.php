<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GuildAnnouncementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GuildAnnouncementRepository::class)]
#[ORM\Table(name: 'guild_announcement')]
class GuildAnnouncement
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Guild $guild = null;

    #[ORM\ManyToOne] #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column(length: 180)] #[Assert\NotBlank]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT)] #[Assert\NotBlank]
    private string $body = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $pinned = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $publishedAt;

    public function __construct() { $this->publishedAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getGuild(): ?Guild { return $this->guild; }
    public function setGuild(Guild $guild): self { $this->guild = $guild; return $this; }
    public function getAuthor(): ?User { return $this->author; }
    public function setAuthor(?User $author): self { $this->author = $author; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = trim($title); return $this; }
    public function getBody(): string { return $this->body; }
    public function setBody(string $body): self { $this->body = trim($body); return $this; }
    public function isPinned(): bool { return $this->pinned; }
    public function setPinned(bool $pinned): self { $this->pinned = $pinned; return $this; }
    public function getPublishedAt(): \DateTimeImmutable { return $this->publishedAt; }
}

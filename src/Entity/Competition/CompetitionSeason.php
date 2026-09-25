<?php

declare(strict_types=1);

namespace App\Entity\Competition;

use App\Entity\Game;
use App\Repository\Competition\CompetitionSeasonRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CompetitionSeasonRepository::class)]
#[ORM\Table(name: 'competition_season')]
class CompetitionSeason
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Game::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Game $game = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $name = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::STATUS_ACTIVE, self::STATUS_ARCHIVED])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->startsAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getGame(): ?Game { return $this->game; }
    public function setGame(Game $game): self { $this->game = $game; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getStartsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(\DateTimeImmutable $startsAt): self { $this->startsAt = $startsAt; return $this; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $endsAt): self { $this->endsAt = $endsAt; return $this; }
    public function getStatus(): string { return $this->status; }
    public function isArchived(): bool { return $this->status === self::STATUS_ARCHIVED; }

    public function archive(): self
    {
        $this->status = self::STATUS_ARCHIVED;
        $this->archivedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getArchivedAt(): ?\DateTimeImmutable { return $this->archivedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function __toString(): string { return $this->name; }
}

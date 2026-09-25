<?php

declare(strict_types=1);

namespace App\Entity\Competition;

use App\Entity\Game;
use App\Entity\User;
use App\Repository\Competition\CompetitionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CompetitionRepository::class)]
#[ORM\Table(name: 'competition')]
#[ORM\UniqueConstraint(name: 'uniq_competition_slug', columns: ['slug'])]
class Competition
{
    public const FORMAT_SINGLE_ELIMINATION = 'single_elimination';
    public const FORMAT_DOUBLE_ELIMINATION = 'double_elimination';
    public const FORMAT_ROUND_ROBIN = 'round_robin';
    public const FORMAT_SWISS = 'swiss';
    public const FORMAT_GROUP_STAGE = 'group_stage';

    public const MODE_SOLO = 'solo';
    public const MODE_TEAM = 'team';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ARCHIVED = 'archived';

    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_PRIVATE = 'private';

    /** @var list<string> */
    public const FORMATS = [
        self::FORMAT_SINGLE_ELIMINATION,
        self::FORMAT_DOUBLE_ELIMINATION,
        self::FORMAT_ROUND_ROBIN,
        self::FORMAT_SWISS,
        self::FORMAT_GROUP_STAGE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Game::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Game $game = null;

    #[ORM\ManyToOne(targetEntity: CompetitionSeason::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CompetitionSeason $season = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $name = '';

    #[ORM\Column(length: 200)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/')]
    private string $slug = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::FORMATS)]
    private string $format = self::FORMAT_SINGLE_ELIMINATION;

    #[ORM\Column(length: 12)]
    #[Assert\Choice(choices: [self::MODE_SOLO, self::MODE_TEAM])]
    private string $mode = self::MODE_SOLO;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::STATUS_DRAFT, self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_ARCHIVED])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(length: 12)]
    #[Assert\Choice(choices: [self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE])]
    private string $visibility = self::VISIBILITY_PUBLIC;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkInDeadline = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $maxParticipants = null;

    #[ORM\Column(options: ['default' => 1])]
    #[Assert\Positive]
    private int $teamSize = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->startsAt = new \DateTimeImmutable('+1 day');
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getGame(): ?Game { return $this->game; }
    public function setGame(Game $game): self
    {
        if ($this->season !== null && $this->season->getGame() !== null && $this->season->getGame() !== $game) { throw new \DomainException('A competition season must belong to the same game.'); }
        $this->game = $game;
        return $this;
    }
    public function getSeason(): ?CompetitionSeason { return $this->season; }
    public function setSeason(?CompetitionSeason $season): self
    {
        if ($season !== null && $this->game !== null && $season->getGame() !== null && $season->getGame() !== $this->game) {
            throw new \DomainException('A competition season must belong to the same game.');
        }
        $this->season = $season;
        return $this;
    }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $createdBy): self { $this->createdBy = $createdBy; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = trim(mb_strtolower($slug)); return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = trim($description); return $this; }
    public function getFormat(): string { return $this->format; }
    public function setFormat(string $format): self
    {
        if (!in_array($format, self::FORMATS, true)) { throw new \InvalidArgumentException('Unsupported competition format.'); }
        $this->format = $format;
        return $this;
    }
    public function getMode(): string { return $this->mode; }
    public function setMode(string $mode): self
    {
        if (!in_array($mode, [self::MODE_SOLO, self::MODE_TEAM], true)) { throw new \InvalidArgumentException('Unsupported competition mode.'); }
        $this->mode = $mode;
        if ($mode === self::MODE_SOLO) { $this->teamSize = 1; }
        return $this;
    }
    public function getStatus(): string { return $this->status; }
    public function getVisibility(): string { return $this->visibility; }
    public function setVisibility(string $visibility): self
    {
        if (!in_array($visibility, [self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE], true)) { throw new \InvalidArgumentException('Unsupported competition visibility.'); }
        $this->visibility = $visibility;
        return $this;
    }
    public function getStartsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(\DateTimeImmutable $startsAt): self { $this->startsAt = $startsAt; return $this; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $endsAt): self { $this->endsAt = $endsAt; return $this; }
    public function getCheckInDeadline(): ?\DateTimeImmutable { return $this->checkInDeadline; }
    public function setCheckInDeadline(?\DateTimeImmutable $deadline): self { $this->checkInDeadline = $deadline; return $this; }
    public function getMaxParticipants(): ?int { return $this->maxParticipants; }
    public function setMaxParticipants(?int $maxParticipants): self { $this->maxParticipants = $maxParticipants; return $this; }
    public function getTeamSize(): int { return $this->teamSize; }
    public function setTeamSize(int $teamSize): self
    {
        if ($teamSize < 1) { throw new \InvalidArgumentException('Team size must be positive.'); }
        if ($this->mode === self::MODE_SOLO && $teamSize !== 1) { throw new \DomainException('Solo competitions always use team size one.'); }
        $this->teamSize = $teamSize;
        return $this;
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function isPublic(): bool { return $this->visibility === self::VISIBILITY_PUBLIC; }
    public function isRegistrationOpen(): bool { return $this->status === self::STATUS_OPEN; }
    public function isArchived(): bool { return $this->status === self::STATUS_ARCHIVED || $this->season?->isArchived() === true; }

    public function open(): self
    {
        if ($this->game === null || !$this->game->isEnabled()) { throw new \DomainException('The competition game must be enabled.'); }
        if ($this->season?->isArchived() === true) { throw new \DomainException('A competition cannot open in an archived season.'); }
        if ($this->name === '' || $this->slug === '') { throw new \DomainException('A competition needs a name and slug.'); }
        if ($this->mode === self::MODE_SOLO && $this->teamSize !== 1) { throw new \DomainException('Solo competitions always use team size one.'); }
        $this->status = self::STATUS_OPEN;
        return $this;
    }

    public function start(): self
    {
        if (!in_array($this->status, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS], true)) { throw new \DomainException('Only open competitions can start.'); }
        $this->status = self::STATUS_IN_PROGRESS;
        return $this;
    }

    public function complete(): self
    {
        if ($this->status !== self::STATUS_IN_PROGRESS) { throw new \DomainException('Only active competitions can be completed.'); }
        $this->status = self::STATUS_COMPLETED;
        return $this;
    }

    public function archive(): self { $this->status = self::STATUS_ARCHIVED; return $this; }
    public function __toString(): string { return $this->name; }
}

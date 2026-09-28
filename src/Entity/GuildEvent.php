<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GuildEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GuildEventRepository::class)]
#[ORM\Table(name: 'guild_event')]
class GuildEvent
{
    public const TYPE_RAID = 'raid';
    public const TYPE_MEETING = 'meeting';
    public const TYPE_TRAINING = 'training';
    public const TYPE_OTHER = 'other';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_DONE = 'done';

    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Guild $guild = null;

    #[ORM\ManyToOne] #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne] #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?GuildTeam $team = null;

    #[ORM\Column(length: 180)] #[Assert\NotBlank] #[Assert\Length(max: 180)]
    private string $title = '';

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::TYPE_RAID, self::TYPE_MEETING, self::TYPE_TRAINING, self::TYPE_OTHER])]
    private string $type = self::TYPE_RAID;

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Assert\GreaterThan(propertyPath: 'startsAt', message: 'Das Ende muss nach dem Beginn liegen.')]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(nullable: true)] #[Assert\Positive]
    private ?int $maxParticipants = null;

    /** @var array<string, int> */
    #[ORM\Column(type: Types::JSON)]
    private array $roleLimits = [];

    #[ORM\Column(length: 140, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::STATUS_PLANNED, self::STATUS_CANCELLED, self::STATUS_DONE])]
    private string $status = self::STATUS_PLANNED;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->startsAt = new \DateTimeImmutable('+1 day'); $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getGuild(): ?Guild { return $this->guild; }
    public function setGuild(Guild $guild): self
    {
        if ($this->team !== null && $this->team->getGuild() !== null && $this->team->getGuild() !== $guild) { throw new \DomainException('A guild event cannot use a team from another guild.'); }
        $this->guild = $guild;
        return $this;
    }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $user): self { $this->createdBy = $user; return $this; }
    public function getTeam(): ?GuildTeam { return $this->team; }
    public function setTeam(?GuildTeam $team): self
    {
        if ($team !== null && $this->guild !== null && $team->getGuild() !== null && $team->getGuild() !== $this->guild) { throw new \DomainException('A guild event cannot use a team from another guild.'); }
        $this->team = $team;
        return $this;
    }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = trim($title); return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = trim($description); return $this; }
    public function getStartsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(\DateTimeImmutable $startsAt): self { $this->startsAt = $startsAt; return $this; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $endsAt): self { $this->endsAt = $endsAt; return $this; }
    public function getMaxParticipants(): ?int { return $this->maxParticipants; }
    public function setMaxParticipants(?int $max): self { $this->maxParticipants = $max; return $this; }
    /** @return array<string, int> */
    public function getRoleLimits(): array { return $this->roleLimits; }
    /** @param array<string, int> $limits */
    public function setRoleLimits(array $limits): self { $this->roleLimits = $limits; return $this; }
    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): self
    {
        if ($location === null) {
            $this->location = null;

            return $this;
        }

        if (!mb_check_encoding($location, 'UTF-8') || str_contains($location, "\0")) {
            throw new \InvalidArgumentException('Guild event location must be valid UTF-8 without NUL bytes.');
        }

        $normalizedLocation = trim($location);
        if ($normalizedLocation === '') {
            $this->location = null;

            return $this;
        }

        if (strlen($normalizedLocation) > 560 || mb_strlen($normalizedLocation, 'UTF-8') > 140) {
            throw new \InvalidArgumentException('Guild event location must fit its 140-character storage column.');
        }

        $this->location = $normalizedLocation;

        return $this;
    }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

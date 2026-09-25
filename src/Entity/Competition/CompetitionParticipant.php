<?php

declare(strict_types=1);

namespace App\Entity\Competition;

use App\Entity\User;
use App\Repository\Competition\CompetitionParticipantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CompetitionParticipantRepository::class)]
#[ORM\Table(name: 'competition_participant')]
#[ORM\UniqueConstraint(name: 'uniq_competition_participant_name', columns: ['competition_id', 'name'])]
class CompetitionParticipant
{
    public const KIND_SOLO = 'solo';
    public const KIND_TEAM = 'team';
    public const STATUS_REGISTERED = 'registered';
    public const STATUS_CHECKED_IN = 'checked_in';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_DISQUALIFIED = 'disqualified';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Competition::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Competition $competition = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $captain = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $name = '';

    #[ORM\Column(length: 12)]
    #[Assert\Choice(choices: [self::KIND_SOLO, self::KIND_TEAM])]
    private string $kind = self::KIND_SOLO;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::STATUS_REGISTERED, self::STATUS_CHECKED_IN, self::STATUS_WITHDRAWN, self::STATUS_DISQUALIFIED])]
    private string $status = self::STATUS_REGISTERED;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $seed = null;

    /** @var list<int> */
    #[ORM\Column(type: Types::JSON)]
    private array $rosterUserIds = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $statusReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $registeredAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $checkedInAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->registeredAt = new \DateTimeImmutable();
        $this->updatedAt = $this->registeredAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCompetition(): ?Competition { return $this->competition; }
    public function setCompetition(Competition $competition): self { $this->competition = $competition; return $this; }
    public function getCaptain(): ?User { return $this->captain; }
    public function setCaptain(User $captain): self { $this->captain = $captain; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }
    public function getKind(): string { return $this->kind; }
    public function setKind(string $kind): self
    {
        if (!in_array($kind, [self::KIND_SOLO, self::KIND_TEAM], true)) { throw new \InvalidArgumentException('Unsupported participant kind.'); }
        $this->kind = $kind;
        return $this;
    }
    public function getStatus(): string { return $this->status; }
    public function getSeed(): ?int { return $this->seed; }
    public function setSeed(?int $seed): self
    {
        if ($seed !== null && $seed < 1) { throw new \InvalidArgumentException('Seed must be positive.'); }
        $this->seed = $seed;
        return $this;
    }
    /** @return list<int> */
    public function getRosterUserIds(): array { return $this->rosterUserIds; }
    /** @param list<int> $userIds */
    public function setRosterUserIds(array $userIds): self
    {
        /** @var list<int> $normalized */
        $normalized = [];
        foreach ($userIds as $userId) {
            if ($userId < 1) { throw new \InvalidArgumentException('Roster user IDs must be positive.'); }
            $normalized[] = $userId;
        }
        $this->rosterUserIds = array_values(array_unique($normalized));
        return $this;
    }
    public function containsUser(User $user): bool
    {
        return ($user->getId() !== null && in_array($user->getId(), $this->rosterUserIds, true))
            || $this->captain === $user
            || ($this->captain?->getId() !== null && $this->captain->getId() === $user->getId());
    }
    public function getStatusReason(): ?string { return $this->statusReason; }
    public function getRegisteredAt(): \DateTimeImmutable { return $this->registeredAt; }
    public function getCheckedInAt(): ?\DateTimeImmutable { return $this->checkedInAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function isActive(): bool { return in_array($this->status, [self::STATUS_REGISTERED, self::STATUS_CHECKED_IN], true); }
    public function isCheckedIn(): bool { return $this->status === self::STATUS_CHECKED_IN; }

    public function checkIn(): self
    {
        if (!$this->isActive()) { throw new \DomainException('Withdrawn or disqualified participants cannot check in.'); }
        if (!$this->competition?->isRegistrationOpen() && $this->competition?->getStatus() !== Competition::STATUS_IN_PROGRESS) {
            throw new \DomainException('The competition is not accepting check-ins.');
        }
        $deadline = $this->competition?->getCheckInDeadline();
        if ($deadline !== null && $deadline < new \DateTimeImmutable()) { throw new \DomainException('The check-in deadline has passed.'); }
        $this->status = self::STATUS_CHECKED_IN;
        $this->checkedInAt = new \DateTimeImmutable();
        $this->touch();
        return $this;
    }

    public function withdraw(?string $reason = null): self
    {
        if (!$this->isActive()) { return $this; }
        $this->status = self::STATUS_WITHDRAWN;
        $this->statusReason = $this->optional($reason);
        $this->touch();
        return $this;
    }

    public function disqualify(string $reason): self
    {
        $reason = trim($reason);
        if ($reason === '') { throw new \InvalidArgumentException('A disqualification needs a reason.'); }
        $this->status = self::STATUS_DISQUALIFIED;
        $this->statusReason = $reason;
        $this->touch();
        return $this;
    }

    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
    private function optional(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        return $value === '' ? null : $value;
    }
}

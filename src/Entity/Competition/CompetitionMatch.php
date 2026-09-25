<?php

declare(strict_types=1);

namespace App\Entity\Competition;

use App\Entity\User;
use App\Repository\Competition\CompetitionMatchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CompetitionMatchRepository::class)]
#[ORM\Table(name: 'competition_match')]
#[ORM\UniqueConstraint(name: 'uniq_competition_match_slot', columns: ['competition_id', 'bracket', 'round_number', 'sequence'])]
class CompetitionMatch
{
    public const BRACKET_WINNERS = 'winners';
    public const BRACKET_LOSERS = 'losers';
    public const BRACKET_GROUP = 'group';
    public const BRACKET_SWISS = 'swiss';
    public const BRACKET_ROUND_ROBIN = 'round_robin';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_READY = 'ready';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Competition::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Competition $competition = null;

    #[ORM\Column]
    private int $roundNumber = 1;

    #[ORM\Column(length: 20)]
    private string $bracket = self::BRACKET_WINNERS;

    #[ORM\Column]
    private int $sequence = 1;

    #[ORM\ManyToOne(targetEntity: CompetitionParticipant::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CompetitionParticipant $participantA = null;

    #[ORM\ManyToOne(targetEntity: CompetitionParticipant::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CompetitionParticipant $participantB = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(length: 24)]
    private string $status = self::STATUS_SCHEDULED;

    #[ORM\Column(nullable: true)]
    private ?int $scoreA = null;

    #[ORM\Column(nullable: true)]
    private ?int $scoreB = null;

    #[ORM\ManyToOne(targetEntity: CompetitionParticipant::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CompetitionParticipant $winner = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $submittedBy = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $participantAConfirmed = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $participantBConfirmed = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resultSubmittedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCompetition(): ?Competition { return $this->competition; }
    public function setCompetition(Competition $competition): self { $this->competition = $competition; return $this; }
    public function getRoundNumber(): int { return $this->roundNumber; }
    public function setRoundNumber(int $roundNumber): self
    {
        if ($roundNumber < 1) { throw new \InvalidArgumentException('Round number must be positive.'); }
        $this->roundNumber = $roundNumber;
        return $this;
    }
    public function getBracket(): string { return $this->bracket; }
    public function setBracket(string $bracket): self
    {
        if (!in_array($bracket, [self::BRACKET_WINNERS, self::BRACKET_LOSERS, self::BRACKET_GROUP, self::BRACKET_SWISS, self::BRACKET_ROUND_ROBIN], true)) { throw new \InvalidArgumentException('Unsupported match bracket.'); }
        $this->bracket = $bracket;
        return $this;
    }
    public function getSequence(): int { return $this->sequence; }
    public function setSequence(int $sequence): self
    {
        if ($sequence < 1) { throw new \InvalidArgumentException('Match sequence must be positive.'); }
        $this->sequence = $sequence;
        return $this;
    }
    public function getParticipantA(): ?CompetitionParticipant { return $this->participantA; }
    public function getParticipantB(): ?CompetitionParticipant { return $this->participantB; }
    public function setParticipants(?CompetitionParticipant $participantA, ?CompetitionParticipant $participantB): self
    {
        foreach ([$participantA, $participantB] as $participant) {
            if ($participant !== null && $participant->getCompetition() !== $this->competition) {
                throw new \DomainException('Match participants must belong to the same competition.');
            }
        }
        if ($participantA !== null && $participantA === $participantB) { throw new \DomainException('A participant cannot play itself.'); }
        $this->participantA = $participantA;
        $this->participantB = $participantB;
        return $this;
    }
    public function getScheduledAt(): ?\DateTimeImmutable { return $this->scheduledAt; }
    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): self { $this->scheduledAt = $scheduledAt; return $this; }
    public function getStatus(): string { return $this->status; }
    public function getScoreA(): ?int { return $this->scoreA; }
    public function getScoreB(): ?int { return $this->scoreB; }
    public function getWinner(): ?CompetitionParticipant { return $this->winner; }
    public function getSubmittedBy(): ?User { return $this->submittedBy; }
    public function isParticipant(CompetitionParticipant $participant): bool { return $this->participantA === $participant || $this->participantB === $participant; }
    public function hasUser(User $user): bool
    {
        return ($this->participantA?->containsUser($user) ?? false) || ($this->participantB?->containsUser($user) ?? false);
    }
    public function hasConfirmedBy(CompetitionParticipant $participant): bool
    {
        return ($this->participantA === $participant && $this->participantAConfirmed) || ($this->participantB === $participant && $this->participantBConfirmed);
    }
    public function isConfirmed(): bool { return $this->status === self::STATUS_CONFIRMED; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function markReady(): self
    {
        if ($this->participantA === null || $this->participantB === null) { throw new \DomainException('A match needs two participants before it can start.'); }
        if ($this->status === self::STATUS_CANCELLED) { throw new \DomainException('Cancelled matches cannot be reopened.'); }
        $this->status = self::STATUS_READY;
        return $this;
    }

    public function submitResult(CompetitionParticipant $by, int $scoreA, int $scoreB, User $submittedBy): self
    {
        if (!$this->isParticipant($by)) { throw new \DomainException('Only match participants may submit a result.'); }
        if (!$by->containsUser($submittedBy)) { throw new \DomainException('The submitting user is not a member of this participant.'); }
        if (!$by->isCheckedIn()) { throw new \DomainException('Only checked-in participants may submit a result.'); }
        if ($scoreA < 0 || $scoreB < 0) { throw new \InvalidArgumentException('Scores cannot be negative.'); }
        if ($scoreA === $scoreB && in_array($this->competition?->getFormat(), [Competition::FORMAT_SINGLE_ELIMINATION, Competition::FORMAT_DOUBLE_ELIMINATION], true)) { throw new \DomainException('Elimination matches cannot end in a draw.'); }
        if (!in_array($this->status, [self::STATUS_READY, self::STATUS_IN_PROGRESS], true)) { throw new \DomainException('This match no longer accepts results.'); }
        $this->scoreA = $scoreA;
        $this->scoreB = $scoreB;
        $this->submittedBy = $submittedBy;
        $this->resultSubmittedAt = new \DateTimeImmutable();
        $this->status = self::STATUS_PENDING_CONFIRMATION;
        $this->setConfirmedFlag($by, true);
        return $this;
    }

    public function confirmResult(CompetitionParticipant $by): self
    {
        if (!$this->isParticipant($by)) { throw new \DomainException('Only match participants may confirm a result.'); }
        if (!$by->isCheckedIn()) { throw new \DomainException('Only checked-in participants may confirm a result.'); }
        if ($this->scoreA === null || $this->scoreB === null) { throw new \DomainException('A result must be submitted before confirmation.'); }
        if ($this->status === self::STATUS_DISPUTED || $this->status === self::STATUS_CANCELLED) { throw new \DomainException('This match is not confirmable.'); }
        $this->setConfirmedFlag($by, true);
        if ($this->participantAConfirmed && $this->participantBConfirmed) {
            $this->status = self::STATUS_CONFIRMED;
            $this->confirmedAt = new \DateTimeImmutable();
            $this->winner = $this->scoreA === $this->scoreB ? null : ($this->scoreA > $this->scoreB ? $this->participantA : $this->participantB);
        }
        return $this;
    }

    public function markDisputed(): self
    {
        if ($this->status !== self::STATUS_PENDING_CONFIRMATION) { throw new \DomainException('Only pending results can be disputed.'); }
        $this->status = self::STATUS_DISPUTED;
        return $this;
    }

    public function resolveDispute(?CompetitionParticipant $winner, int $scoreA, int $scoreB): self
    {
        if ($this->status !== self::STATUS_DISPUTED) { throw new \DomainException('Only disputed matches can be resolved.'); }
        if ($scoreA < 0 || $scoreB < 0) { throw new \InvalidArgumentException('Scores cannot be negative.'); }
        if ($scoreA === $scoreB && in_array($this->competition?->getFormat(), [Competition::FORMAT_SINGLE_ELIMINATION, Competition::FORMAT_DOUBLE_ELIMINATION], true)) { throw new \DomainException('Elimination matches cannot end in a draw.'); }
        if ($winner !== null && !$this->isParticipant($winner)) { throw new \DomainException('The winner must be a match participant.'); }
        $expectedWinner = $scoreA === $scoreB ? null : ($scoreA > $scoreB ? $this->participantA : $this->participantB);
        if ($winner !== $expectedWinner) { throw new \DomainException('The winner must match the resolved scores.'); }
        $this->scoreA = $scoreA;
        $this->scoreB = $scoreB;
        $this->winner = $winner;
        $this->participantAConfirmed = true;
        $this->participantBConfirmed = true;
        $this->status = self::STATUS_CONFIRMED;
        $this->confirmedAt = new \DateTimeImmutable();
        return $this;
    }

    private function setConfirmedFlag(CompetitionParticipant $participant, bool $value): void
    {
        if ($this->participantA === $participant) { $this->participantAConfirmed = $value; return; }
        if ($this->participantB === $participant) { $this->participantBConfirmed = $value; return; }
        throw new \DomainException('Participant is not assigned to this match.');
    }
}

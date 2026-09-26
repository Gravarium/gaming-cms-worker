<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GuildApplicationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GuildApplicationRepository::class)]
#[ORM\Table(name: 'guild_application')]
class GuildApplication
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_REVIEWING = 'reviewing';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Guild $guild = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?GuildMember $convertedMember = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $applicantName = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $characterName = '';

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $characterClass = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 20, max: 5000)]
    private string $message = '';

    /** @var list<array{question: string, answer: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $answers = [];

    #[ORM\Column(length: 12)]
    #[Assert\Choice(choices: [self::STATUS_PENDING, self::STATUS_REVIEWING, self::STATUS_ACCEPTED, self::STATUS_REJECTED])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $internalNotes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getGuild(): ?Guild { return $this->guild; }
    public function setGuild(Guild $guild): self { $this->guild = $guild; return $this; }
    public function getConvertedMember(): ?GuildMember { return $this->convertedMember; }
    public function getAssignedTo(): ?User { return $this->assignedTo; }
    public function assignTo(?User $user): self { $this->assignedTo = $user; if ($user !== null && $this->status === self::STATUS_PENDING) { $this->status = self::STATUS_REVIEWING; } elseif ($user === null && $this->status === self::STATUS_REVIEWING) { $this->status = self::STATUS_PENDING; } return $this; }
    public function setConvertedMember(?GuildMember $member): self
    {
        if ($member !== null && $this->guild !== null && $member->getGuild() !== $this->guild) {
            throw new \DomainException('A guild application cannot convert to a member of another guild.');
        }
        $this->convertedMember = $member;

        return $this;
    }
    public function getApplicantName(): string { return $this->applicantName; }
    public function setApplicantName(string $value): self { $this->applicantName = trim($value); return $this; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): self { $this->email = mb_strtolower(trim($email)); return $this; }
    public function getCharacterName(): string { return $this->characterName; }
    public function setCharacterName(string $value): self { $this->characterName = trim($value); return $this; }
    public function getCharacterClass(): ?string { return $this->characterClass; }
    public function setCharacterClass(?string $value): self { $value = $value === null ? null : trim($value); $this->characterClass = $value === '' ? null : $value; return $this; }
    public function getMessage(): string { return $this->message; }
    public function setMessage(string $message): self
    {
        if (!mb_check_encoding($message, 'UTF-8') || str_contains($message, "\0")) {
            throw new \InvalidArgumentException('Guild application message must be valid UTF-8 without NUL bytes.');
        }

        $normalizedMessage = trim($message);
        if (strlen($normalizedMessage) > 20000 || mb_strlen($normalizedMessage, 'UTF-8') > 5000) {
            throw new \InvalidArgumentException('Guild application message must not exceed 5,000 characters.');
        }

        $this->message = $normalizedMessage;

        return $this;
    }
    /** @return list<array{question: string, answer: string}> */
    public function getAnswers(): array { return $this->answers; }
    /** @param list<array{question: string, answer: string}> $answers */
    public function setAnswers(array $answers): self { $this->answers = $answers; return $this; }
    public function getStatus(): string { return $this->status; }
    public function isOpen(): bool { return in_array($this->status, [self::STATUS_PENDING, self::STATUS_REVIEWING], true); }
    public function getInternalNotes(): ?string { return $this->internalNotes; }
    public function setInternalNotes(?string $notes): self { $notes = $notes === null ? null : trim($notes); $this->internalNotes = $notes === '' ? null : $notes; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }
    public function accept(): self { $this->ensureOpen(); $this->status = self::STATUS_ACCEPTED; $this->reviewedAt = new \DateTimeImmutable(); return $this; }
    public function reject(): self { $this->ensureOpen(); $this->status = self::STATUS_REJECTED; $this->reviewedAt = new \DateTimeImmutable(); return $this; }

    private function ensureOpen(): void
    {
        if (!$this->isOpen()) { throw new \DomainException('A decided guild application cannot be decided again.'); }
    }
}

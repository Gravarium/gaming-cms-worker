<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GuildEventSignupRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GuildEventSignupRepository::class)]
#[ORM\Table(name: 'guild_event_signup')]
#[ORM\UniqueConstraint(name: 'uniq_event_member_signup', columns: ['event_id', 'member_id'])]
class GuildEventSignup
{
    public const GOING = 'going';
    public const MAYBE = 'maybe';
    public const DECLINED = 'declined';
    public const WAITLIST = 'waitlist';
    public const ATTENDANCE_UNKNOWN = 'unknown';
    public const ATTENDANCE_PRESENT = 'present';
    public const ATTENDANCE_ABSENT = 'absent';
    public const ATTENDANCE_EXCUSED = 'excused';

    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?GuildEvent $event = null;

    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?GuildMember $member = null;

    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 20)]
    private string $response = self::GOING;

    #[ORM\Column(length: 20)]
    private string $role = 'other';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(length: 20, options: ['default' => 'unknown'])]
    private string $attendance = self::ATTENDANCE_UNKNOWN;

    #[ORM\ManyToOne] #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $attendanceCheckedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $attendanceCheckedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct() { $this->updatedAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getEvent(): ?GuildEvent { return $this->event; }
    public function setEvent(GuildEvent $event): self { $this->event = $event; return $this; }
    public function getMember(): ?GuildMember { return $this->member; }
    public function setMember(GuildMember $member): self { $this->member = $member; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }
    public function getResponse(): string { return $this->response; }
    public function setResponse(string $response): self { $this->response = $response; $this->updatedAt = new \DateTimeImmutable(); return $this; }
    public function getRole(): string { return $this->role; }
    public function setRole(string $role): self { $this->role = $role; return $this; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): self { $this->note = $note === null || trim($note) === '' ? null : trim($note); return $this; }
    public function getAttendance(): string { return $this->attendance; }
    public function markAttendance(string $attendance, ?User $checkedBy): self
    {
        if (!in_array($attendance, [self::ATTENDANCE_UNKNOWN, self::ATTENDANCE_PRESENT, self::ATTENDANCE_ABSENT, self::ATTENDANCE_EXCUSED], true)) {
            throw new \InvalidArgumentException('Invalid attendance state.');
        }
        $this->attendance = $attendance;
        $this->attendanceCheckedBy = $checkedBy;
        $this->attendanceCheckedAt = $attendance === self::ATTENDANCE_UNKNOWN ? null : new \DateTimeImmutable();
        return $this;
    }
    public function getAttendanceCheckedBy(): ?User { return $this->attendanceCheckedBy; }
    public function getAttendanceCheckedAt(): ?\DateTimeImmutable { return $this->attendanceCheckedAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}

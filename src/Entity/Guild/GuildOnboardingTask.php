<?php

declare(strict_types=1);

namespace App\Entity\Guild;

use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'guild_onboarding_task')]
class GuildOnboardingTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Guild $guild;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GuildMember $member;

    #[ORM\Column(length: 180)]
    private string $label;

    #[ORM\Column(options: ['default' => false])]
    private bool $completed = false;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $completedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(Guild $guild, GuildMember $member, string $label)
    {
        $label = trim($label);
        if ($member->getGuild() !== $guild) {
            throw new \DomainException('Onboarding member must belong to the same guild.');
        }
        if ($label === '') {
            throw new \InvalidArgumentException('Onboarding task label is required.');
        }
        $this->guild = $guild;
        $this->member = $member;
        $this->label = $label;
    }

    public function complete(User $actor): void
    {
        if ($this->completed) {
            throw new \DomainException('Onboarding task is already completed.');
        }
        $this->completed = true;
        $this->completedBy = $actor;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function isCompleted(): bool { return $this->completed; }
}

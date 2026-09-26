<?php

declare(strict_types=1);

namespace App\Entity\Guild;

use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'guild_member_private_note')]
class GuildMemberPrivateNote
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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Guild $guild, GuildMember $member, User $author, string $body)
    {
        $body = trim($body);
        if ($member->getGuild() !== $guild) {
            throw new \DomainException('Private note member must belong to the same guild.');
        }
        if ($body === '' || mb_strlen($body) > 5000) {
            throw new \InvalidArgumentException('Private note is empty or too long.');
        }
        $this->guild = $guild;
        $this->member = $member;
        $this->author = $author;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getBody(): string { return $this->body; }
}

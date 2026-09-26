<?php

declare(strict_types=1);

namespace App\Entity\Social;

use App\Entity\User;
use App\Repository\Social\SocialPrivacySettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SocialPrivacySettingsRepository::class)]
#[ORM\Table(name: 'social_privacy_settings')]
class SocialPrivacySettings
{
    public const MESSAGE_EVERYONE = 'everyone';
    public const MESSAGE_RELATIONSHIPS = 'relationships';
    public const MESSAGE_NOBODY = 'nobody';
    public const RELATIONSHIPS_EVERYONE = 'everyone';
    public const RELATIONSHIPS_NOBODY = 'nobody';

    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16)]
    private string $messagePolicy = self::MESSAGE_EVERYONE;

    #[ORM\Column(length: 16)]
    private string $relationshipPolicy = self::RELATIONSHIPS_EVERYONE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUser(): User { return $this->user; }
    public function getMessagePolicy(): string { return $this->messagePolicy; }
    public function getRelationshipPolicy(): string { return $this->relationshipPolicy; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setMessagePolicy(string $policy): self
    {
        if (!in_array($policy, [self::MESSAGE_EVERYONE, self::MESSAGE_RELATIONSHIPS, self::MESSAGE_NOBODY], true)) {
            throw new \InvalidArgumentException('Unknown message privacy policy.');
        }
        $this->messagePolicy = $policy;
        $this->touch();

        return $this;
    }

    public function setRelationshipPolicy(string $policy): self
    {
        if (!in_array($policy, [self::RELATIONSHIPS_EVERYONE, self::RELATIONSHIPS_NOBODY], true)) {
            throw new \InvalidArgumentException('Unknown relationship privacy policy.');
        }
        $this->relationshipPolicy = $policy;
        $this->touch();

        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

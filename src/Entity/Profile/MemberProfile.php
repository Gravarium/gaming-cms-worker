<?php

declare(strict_types=1);

namespace App\Entity\Profile;

use App\Entity\MediaAsset;
use App\Entity\User;
use App\Repository\Profile\MemberProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MemberProfileRepository::class)]
#[ORM\Table(name: 'member_profile')]
class MemberProfile
{
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_MEMBERS = 'members';
    public const VISIBILITY_PRIVATE = 'private';

    public const FIELD_DISPLAY_NAME = 'display_name';
    public const FIELD_BIO = 'bio';
    public const FIELD_AVATAR = 'avatar';
    public const FIELD_BANNER = 'banner';

    private const FIELDS = [
        self::FIELD_DISPLAY_NAME,
        self::FIELD_BIO,
        self::FIELD_AVATAR,
        self::FIELD_BANNER,
    ];

    private const VISIBILITIES = [
        self::VISIBILITY_PUBLIC,
        self::VISIBILITY_MEMBERS,
        self::VISIBILITY_PRIVATE,
    ];

    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $bio = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'avatar_asset_id', onDelete: 'SET NULL')]
    private ?MediaAsset $avatar = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'banner_asset_id', onDelete: 'SET NULL')]
    private ?MediaAsset $banner = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $visibility = [
        self::FIELD_DISPLAY_NAME => self::VISIBILITY_PUBLIC,
        self::FIELD_BIO => self::VISIBILITY_MEMBERS,
        self::FIELD_AVATAR => self::VISIBILITY_MEMBERS,
        self::FIELD_BANNER => self::VISIBILITY_MEMBERS,
    ];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUser(): User { return $this->user; }
    public function getBio(): ?string { return $this->bio; }

    public function setBio(?string $bio): self
    {
        $bio = $bio === null ? null : trim($bio);
        if ($bio !== null && mb_strlen($bio) > 2000) {
            throw new \InvalidArgumentException('Profile bio is too long.');
        }
        $this->bio = $bio === '' ? null : $bio;
        $this->touch();
        return $this;
    }

    public function getAvatar(): ?MediaAsset { return $this->avatar; }
    public function setAvatar(?MediaAsset $avatar): self { $this->avatar = $avatar; $this->touch(); return $this; }
    public function getBanner(): ?MediaAsset { return $this->banner; }
    public function setBanner(?MediaAsset $banner): self { $this->banner = $banner; $this->touch(); return $this; }

    /** @return array<string, string> */
    public function getVisibility(): array { return $this->visibility; }

    public function visibilityFor(string $field): string
    {
        $this->assertField($field);
        return $this->visibility[$field] ?? self::VISIBILITY_PRIVATE;
    }

    public function setFieldVisibility(string $field, string $visibility): self
    {
        $this->assertField($field);
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            throw new \InvalidArgumentException('Unknown profile visibility.');
        }
        $this->visibility[$field] = $visibility;
        $this->touch();
        return $this;
    }

    public function getDisplayNameVisibility(): string { return $this->visibilityFor(self::FIELD_DISPLAY_NAME); }
    public function setDisplayNameVisibility(string $value): self { return $this->setFieldVisibility(self::FIELD_DISPLAY_NAME, $value); }
    public function getBioVisibility(): string { return $this->visibilityFor(self::FIELD_BIO); }
    public function setBioVisibility(string $value): self { return $this->setFieldVisibility(self::FIELD_BIO, $value); }
    public function getAvatarVisibility(): string { return $this->visibilityFor(self::FIELD_AVATAR); }
    public function setAvatarVisibility(string $value): self { return $this->setFieldVisibility(self::FIELD_AVATAR, $value); }
    public function getBannerVisibility(): string { return $this->visibilityFor(self::FIELD_BANNER); }
    public function setBannerVisibility(string $value): self { return $this->setFieldVisibility(self::FIELD_BANNER, $value); }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    private function assertField(string $field): void
    {
        if (!in_array($field, self::FIELDS, true)) {
            throw new \InvalidArgumentException('Unknown profile field.');
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

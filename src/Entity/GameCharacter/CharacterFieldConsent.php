<?php

declare(strict_types=1);

namespace App\Entity\GameCharacter;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'game_character_field_consent')]
#[ORM\UniqueConstraint(name: 'uniq_game_character_field_consent', columns: ['profile_id', 'field_key'])]
#[ORM\Index(name: 'idx_game_character_field_consent_profile', columns: ['profile_id'])]
final class CharacterFieldConsent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'consents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CharacterProfile $profile;

    #[ORM\Column(length: 40)]
    private string $fieldKey;

    #[ORM\Column(options: ['default' => false])]
    private bool $granted = false;

    #[ORM\Column(length: 30)]
    private string $source;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $grantedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(CharacterProfile $profile, string $fieldKey, string $source = 'user')
    {
        if (!in_array($fieldKey, CharacterProfile::FIELDS, true)) {
            throw new \InvalidArgumentException('Unknown character profile field.');
        }
        $source = trim($source);
        if ($source === '' || mb_strlen($source) > 30) {
            throw new \InvalidArgumentException('Consent source is required and bounded.');
        }

        $this->profile = $profile;
        $this->fieldKey = $fieldKey;
        $this->source = $source;
    }

    public function getId(): ?int { return $this->id; }
    public function getProfile(): CharacterProfile { return $this->profile; }
    public function getFieldKey(): string { return $this->fieldKey; }
    public function isGranted(): bool { return $this->granted && $this->revokedAt === null; }
    public function getSource(): string { return $this->source; }
    public function getGrantedAt(): ?\DateTimeImmutable { return $this->grantedAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function grant(string $source = 'user'): self
    {
        $source = trim($source);
        if ($source === '' || mb_strlen($source) > 30) { throw new \InvalidArgumentException('Consent source is required and bounded.'); }
        $this->source = $source;
        $this->granted = true;
        $this->grantedAt = new \DateTimeImmutable();
        $this->revokedAt = null;

        return $this;
    }
    public function revoke(string $source = 'user'): self
    {
        $source = trim($source);
        if ($source === '' || mb_strlen($source) > 30) { throw new \InvalidArgumentException('Consent source is required and bounded.'); }
        $this->source = $source;
        $this->granted = false;
        $this->revokedAt = new \DateTimeImmutable();

        return $this;
    }
}

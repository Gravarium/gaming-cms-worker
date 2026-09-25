<?php

declare(strict_types=1);

namespace App\Entity\GameCharacter;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'game_character_import_provenance')]
#[ORM\Index(name: 'idx_game_character_import_profile_observed', columns: ['profile_id', 'observed_at'])]
final class CharacterImportProvenance
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CONFLICT = 'conflict';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'provenance')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CharacterProfile $profile;

    #[ORM\Column(length: 80)]
    private string $provider;

    #[ORM\Column(length: 160)]
    private string $externalId;

    #[ORM\Column(length: 128)]
    private string $payloadHash;

    #[ORM\Column(length: 16)]
    private string $status;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $observedAt;

    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    public function __construct(
        CharacterProfile $profile,
        string $provider,
        string $externalId,
        string $payloadHash,
        \DateTimeImmutable $observedAt,
        string $status = self::STATUS_ACCEPTED,
        array $metadata = [],
    ) {
        $provider = trim($provider);
        $externalId = trim($externalId);
        $payloadHash = trim($payloadHash);
        if ($provider === '' || mb_strlen($provider) > 80 || $externalId === '' || mb_strlen($externalId) > 160) {
            throw new \InvalidArgumentException('Import provider and external identity are required and bounded.');
        }
        if ($payloadHash === '' || mb_strlen($payloadHash) > 128) {
            throw new \InvalidArgumentException('Import payload hash is required and bounded.');
        }
        if (!in_array($status, [self::STATUS_ACCEPTED, self::STATUS_REJECTED, self::STATUS_CONFLICT], true)) {
            throw new \InvalidArgumentException('Unknown import provenance status.');
        }

        $this->profile = $profile;
        $this->provider = $provider;
        $this->externalId = $externalId;
        $this->payloadHash = $payloadHash;
        $this->observedAt = $observedAt;
        $this->status = $status;
        $this->metadata = $metadata;
    }

    public function getId(): ?int { return $this->id; }
    public function getProfile(): CharacterProfile { return $this->profile; }
    public function getProvider(): string { return $this->provider; }
    public function getExternalId(): string { return $this->externalId; }
    public function getPayloadHash(): string { return $this->payloadHash; }
    public function getStatus(): string { return $this->status; }
    public function getObservedAt(): \DateTimeImmutable { return $this->observedAt; }
    /** @return array<string, mixed> */
    public function getMetadata(): array { return $this->metadata; }
}

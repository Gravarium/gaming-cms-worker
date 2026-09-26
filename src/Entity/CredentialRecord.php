<?php
declare(strict_types=1);
namespace App\Entity;
use App\Repository\CredentialRecordRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord as BaseCredentialRecord;
use Webauthn\TrustPath\TrustPath;
#[ORM\Entity(repositoryClass: CredentialRecordRepository::class)]
#[ORM\Table(name: 'passkey_credential')]
#[ORM\UniqueConstraint(name: 'uniq_passkey_credential_id', columns: ['public_key_credential_id'])]
class CredentialRecord extends BaseCredentialRecord
{
    #[ORM\Id] #[ORM\Column(type: Types::STRING, length: 36)] #[ORM\GeneratedValue(strategy: 'NONE')]
    private string $id;
    #[ORM\Column(length: 80)] private string $name;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)] private DateTimeImmutable $createdAt;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)] private ?DateTimeImmutable $lastUsedAt = null;
    /** @param list<string> $transports @param array<string, mixed>|null $otherUI */
    public function __construct(string $publicKeyCredentialId, string $type, array $transports, string $attestationType, TrustPath $trustPath, Uuid $aaguid, string $credentialPublicKey, string $userHandle, int $counter, ?array $otherUI = null, ?bool $backupEligible = null, ?bool $backupStatus = null, ?bool $uvInitialized = null, string $name = 'Mein Passkey')
    {
        $this->id = Uuid::v4()->toRfc4122(); $this->name = mb_substr(trim($name), 0, 80); $this->createdAt = new DateTimeImmutable();
        parent::__construct($publicKeyCredentialId, $type, $transports, $attestationType, $trustPath, $aaguid, $credentialPublicKey, $userHandle, $counter, $otherUI, $backupEligible, $backupStatus, $uvInitialized);
    }
    public static function fromCredentialRecord(BaseCredentialRecord $credential): self
    {
        return new self($credential->publicKeyCredentialId, $credential->type, array_values($credential->transports), $credential->attestationType, $credential->trustPath, $credential->aaguid, $credential->credentialPublicKey, $credential->userHandle, $credential->counter, $credential->otherUI, $credential->backupEligible, $credential->backupStatus, $credential->uvInitialized);
    }
    public function applyAuthenticationResult(BaseCredentialRecord $credential): void
    {
        $this->counter = $credential->counter; $this->backupEligible = $credential->backupEligible; $this->backupStatus = $credential->backupStatus; $this->uvInitialized = $credential->uvInitialized; $this->lastUsedAt = new DateTimeImmutable();
    }
    public function getId(): string { return $this->id; }
    public function getName(): string { return $this->name; }
    public function rename(string $name): void { $this->name = mb_substr(trim($name), 0, 80); }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getLastUsedAt(): ?DateTimeImmutable { return $this->lastUsedAt; }
}

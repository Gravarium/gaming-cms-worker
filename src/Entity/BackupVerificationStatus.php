<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BackupVerificationStatusRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BackupVerificationStatusRepository::class)]
#[ORM\Table(name: 'backup_verification_status')]
#[ORM\UniqueConstraint(name: 'uniq_backup_verification_id', columns: ['backup_id'])]
class BackupVerificationStatus
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $backupId = '';

    #[ORM\Column]
    private bool $successful = false;

    #[ORM\Column]
    private \DateTimeImmutable $checkedAt;

    public function __construct() { $this->checkedAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getBackupId(): string { return $this->backupId; }
    public function setBackupId(string $backupId): self
    {
        if (preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-fA-F]{7,12}$/', $backupId) !== 1) {
            throw new \InvalidArgumentException('Invalid backup ID.');
        }
        $this->backupId = $backupId;
        return $this;
    }
    public function isSuccessful(): bool { return $this->successful; }
    public function setSuccessful(bool $successful): self { $this->successful = $successful; return $this; }
    public function getCheckedAt(): \DateTimeImmutable { return $this->checkedAt; }
    public function setCheckedAt(\DateTimeImmutable $checkedAt): self { $this->checkedAt = $checkedAt; return $this; }
}

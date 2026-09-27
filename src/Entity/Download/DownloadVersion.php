<?php

declare(strict_types=1);

namespace App\Entity\Download;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'download_version')]
#[ORM\UniqueConstraint(name: 'uniq_download_package_version', columns: ['package_id', 'version'])]
class DownloadVersion
{
    public const SCAN_PENDING = 'pending';
    public const SCAN_CLEAN = 'clean';
    public const SCAN_REJECTED = 'rejected';
    public const SCAN_UNAVAILABLE = 'unavailable';

    public const SCAN_ERROR_MALWARE_REJECTED = 'malware_rejected';
    public const SCAN_ERROR_STORAGE_UNAVAILABLE = 'storage_unavailable';
    public const SCAN_ERROR_SCANNER_UNAVAILABLE = 'scanner_unavailable';
    public const SCAN_ERROR_SCAN_FAILED = 'scan_failed';

    public const SCAN = [
        self::SCAN_PENDING,
        self::SCAN_CLEAN,
        self::SCAN_REJECTED,
        self::SCAN_UNAVAILABLE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DownloadPackage $package;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?self $replacedBy = null;

    #[ORM\Column(length: 80)]
    private string $version;

    #[ORM\Column(length: 255)]
    private string $safeFilename;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(length: 20)]
    private string $scanStatus = self::SCAN_PENDING;

    #[ORM\Column(options: ['default' => 0])]
    private int $scanAttempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastScannedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $scanError = null;

    #[ORM\Column(length: 500)]
    private string $storageReference;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $compatibility = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $changelog = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $obsolete = false;

    public function __construct(
        DownloadPackage $package,
        string $version,
        string $safeFilename,
        string $sha256,
        string $storageReference,
    ) {
        $version = trim($version);
        if ($version === '' || preg_match('/^[A-Za-z0-9._+-]{1,80}$/D', $version) !== 1) {
            throw new \InvalidArgumentException('Invalid version.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/D', $safeFilename) !== 1) {
            throw new \InvalidArgumentException('Unsafe filename.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new \InvalidArgumentException('Invalid SHA-256.');
        }
        if (
            $storageReference === ''
            || str_starts_with($storageReference, '/')
            || str_contains($storageReference, '\\')
            || str_contains($storageReference, '..')
        ) {
            throw new \InvalidArgumentException('Invalid private storage reference.');
        }

        $this->package = $package;
        $this->version = $version;
        $this->safeFilename = $safeFilename;
        $this->sha256 = $sha256;
        $this->storageReference = $storageReference;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPackage(): DownloadPackage
    {
        return $this->package;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getSafeFilename(): string
    {
        return $this->safeFilename;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getStorageReference(): string
    {
        return $this->storageReference;
    }

    public function getScanStatus(): string
    {
        return $this->scanStatus;
    }

    public function getScanAttempts(): int
    {
        return $this->scanAttempts;
    }

    public function getLastScannedAt(): ?\DateTimeImmutable
    {
        return $this->lastScannedAt;
    }

    public function getScanError(): ?string
    {
        return $this->scanError;
    }

    public function isObsolete(): bool
    {
        return $this->obsolete;
    }

    public function getReplacedBy(): ?self
    {
        return $this->replacedBy;
    }

    /** @param list<string> $compatibility */
    public function setCompatibility(array $compatibility): self
    {
        $values = array_map(
            static fn (string $value): string => trim($value),
            $compatibility,
        );
        $this->compatibility = array_values(array_unique(array_filter(
            $values,
            static fn (string $value): bool => $value !== '',
        )));

        return $this;
    }

    /** @return list<string> */
    public function getCompatibility(): array
    {
        return $this->compatibility;
    }

    public function setChangelog(?string $value): self
    {
        $value = $value === null ? null : trim($value);
        $this->changelog = $value === '' ? null : $value;

        return $this;
    }

    public function getChangelog(): ?string
    {
        return $this->changelog;
    }

    public function beginScan(): self
    {
        $this->scanStatus = self::SCAN_PENDING;
        $this->scanError = null;

        return $this;
    }

    public function markScan(string $status, ?string $error = null): self
    {
        if (!in_array($status, self::SCAN, true)) {
            throw new \InvalidArgumentException('Invalid scan status.');
        }
        if ($error !== null) {
            $error = trim($error);
            if ($error === '' || preg_match('/^[a-z0-9_.-]{1,64}$/D', $error) !== 1) {
                throw new \InvalidArgumentException('Invalid scan error.');
            }
        }

        $this->scanStatus = $status;
        $this->scanError = $error;
        if ($status !== self::SCAN_PENDING) {
            ++$this->scanAttempts;
            $this->lastScannedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function replaceWith(self $replacement): void
    {
        if ($replacement->package !== $this->package) {
            throw new \DomainException('Replacement must belong to same package.');
        }

        $this->obsolete = true;
        $this->replacedBy = $replacement;
    }

    public function isDeliverable(): bool
    {
        return !$this->obsolete
            && $this->scanStatus === self::SCAN_CLEAN
            && $this->package->isEnabled();
    }
}

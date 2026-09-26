<?php

declare(strict_types=1);

namespace App\Entity\Social;

use App\Entity\User;
use App\Repository\Social\SocialAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SocialAttachmentRepository::class)]
#[ORM\Table(name: 'social_attachment')]
#[ORM\Index(name: 'idx_social_attachment_message', columns: ['message_id'])]
class SocialAttachment
{
    /** @var list<string> */
    public const SAFE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SocialMessage $message;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $uploadedBy;

    #[ORM\Column(length: 500)]
    private string $storageLocator;

    #[ORM\Column(length: 180)]
    private string $originalName;

    #[ORM\Column(length: 120)]
    private string $mimeType;

    #[ORM\Column]
    private int $fileSize;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        SocialMessage $message,
        User $uploadedBy,
        string $storageLocator,
        string $originalName,
        string $mimeType,
        int $fileSize,
    ) {
        $storageLocator = trim($storageLocator);
        $originalName = trim($originalName);
        $mimeType = trim(mb_strtolower($mimeType));
        if ($storageLocator === '' || str_contains($storageLocator, '..') || str_starts_with($storageLocator, 'http://') || str_starts_with($storageLocator, 'https://')) {
            throw new \InvalidArgumentException('Attachments must use an internal storage locator.');
        }
        if ($originalName === '' || mb_strlen($originalName) > 180 || $fileSize < 1 || $fileSize > 25_000_000) {
            throw new \InvalidArgumentException('Invalid attachment metadata.');
        }
        if (!in_array($mimeType, self::SAFE_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Attachment type is not allowed.');
        }

        $this->message = $message;
        $this->uploadedBy = $uploadedBy;
        $this->storageLocator = $storageLocator;
        $this->originalName = $originalName;
        $this->mimeType = $mimeType;
        $this->fileSize = $fileSize;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getMessage(): SocialMessage { return $this->message; }
    public function getUploadedBy(): User { return $this->uploadedBy; }
    public function getStorageLocator(): string { return $this->storageLocator; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getFileSize(): int { return $this->fileSize; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function isAccessible(): bool { return $this->revokedAt === null && !$this->message->isDeleted(); }

    public function revoke(\DateTimeImmutable $at): self
    {
        $this->revokedAt = $at;

        return $this;
    }
}

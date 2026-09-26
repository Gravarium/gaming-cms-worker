<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdminNotificationRepository;
use App\Security\LocalRedirectTarget;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AdminNotificationRepository::class)]
#[ORM\Table(name: 'admin_notification')]
class AdminNotification
{
    private const MAX_TYPE_CHARACTERS = 60;
    private const MAX_TYPE_BYTES = 240;
    private const MAX_TITLE_CHARACTERS = 180;
    private const MAX_TITLE_BYTES = 720;
    private const MAX_MESSAGE_CHARACTERS = 4000;
    private const MAX_MESSAGE_BYTES = 16000;
    private const MAX_LINK_CHARACTERS = 500;
    private const MAX_LINK_BYTES = 2000;
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    private string $type = 'system';

    #[ORM\Column(length: 180)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $message = '';

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $link = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): self
    {
        $this->type = $this->normalize($type, self::MAX_TYPE_CHARACTERS, self::MAX_TYPE_BYTES, 'type');

        return $this;
    }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self
    {
        $this->title = $this->normalize($title, self::MAX_TITLE_CHARACTERS, self::MAX_TITLE_BYTES, 'title');

        return $this;
    }
    public function getMessage(): string { return $this->message; }
    public function setMessage(string $message): self
    {
        $this->message = $this->normalize($message, self::MAX_MESSAGE_CHARACTERS, self::MAX_MESSAGE_BYTES, 'message');

        return $this;
    }
    public function getLink(): ?string { return $this->link; }
    public function setLink(?string $link): self
    {
        $this->link = $link === null
            ? null
            : LocalRedirectTarget::requireSafe($this->normalize($link, self::MAX_LINK_CHARACTERS, self::MAX_LINK_BYTES, 'link'));

        return $this;
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
    public function isRead(): bool { return $this->readAt !== null; }
    public function markRead(): self { $this->readAt ??= new \DateTimeImmutable(); return $this; }

    private function normalize(string $value, int $maxCharacters, int $maxBytes, string $field): string
    {
        if (strlen($value) > $maxBytes) {
            throw new \\LengthException('Notification '.$field.' exceeds its UTF-8 byte limit.');
        }
        if (str_contains($value, "\\0") || !mb_check_encoding($value, 'UTF-8')) {
            throw new \\InvalidArgumentException('Notification '.$field.' must be valid UTF-8 without NUL bytes.');
        }

        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > $maxCharacters) {
            throw new \\LengthException('Notification '.$field.' exceeds its character limit.');
        }

        return $value;
    }
}

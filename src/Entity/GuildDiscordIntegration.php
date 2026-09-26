<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GuildDiscordIntegrationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GuildDiscordIntegrationRepository::class)]
#[ORM\Table(name: 'guild_discord_integration')]
class GuildDiscordIntegration
{
    private const MAX_ENCRYPTED_WEBHOOK_BYTES = 8192;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Guild $guild = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedWebhookUrl = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $enabled = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $notifyEvents = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $notifyAnnouncements = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getGuild(): ?Guild { return $this->guild; }
    public function setGuild(Guild $guild): self { $this->guild = $guild; return $this; }
    public function getEncryptedWebhookUrl(): string { return $this->encryptedWebhookUrl; }
    public function setEncryptedWebhookUrl(string $encryptedWebhookUrl): self
    {
        if (!mb_check_encoding($encryptedWebhookUrl, 'UTF-8')
            || str_contains($encryptedWebhookUrl, "\0")
            || strlen($encryptedWebhookUrl) > self::MAX_ENCRYPTED_WEBHOOK_BYTES
        ) {
            throw new \InvalidArgumentException('Encrypted Discord webhook payload is invalid or exceeds the storage limit.');
        }

        $this->encryptedWebhookUrl = $encryptedWebhookUrl;
        $this->touch();

        return $this;
    }
    public function hasWebhook(): bool { return $this->encryptedWebhookUrl !== ''; }
    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; $this->touch(); return $this; }
    public function isNotifyEvents(): bool { return $this->notifyEvents; }
    public function setNotifyEvents(bool $notifyEvents): self { $this->notifyEvents = $notifyEvents; $this->touch(); return $this; }
    public function isNotifyAnnouncements(): bool { return $this->notifyAnnouncements; }
    public function setNotifyAnnouncements(bool $notifyAnnouncements): self { $this->notifyAnnouncements = $notifyAnnouncements; $this->touch(); return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}

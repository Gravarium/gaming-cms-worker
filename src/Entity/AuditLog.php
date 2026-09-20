<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
class AuditLog
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column]
    private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $actor = null;
    #[ORM\Column(length: 80)]
    private string $action = '';
    #[ORM\Column(length: 120)]
    private string $subjectType = '';
    #[ORM\Column(nullable: true)]
    private ?int $subjectId = null;
    #[ORM\Column(length: 255)]
    private string $summary = '';
    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $context = [];
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getActor(): ?User { return $this->actor; }
    public function setActor(?User $actor): self { $this->actor = $actor; return $this; }
    public function getAction(): string { return $this->action; }
    public function setAction(string $action): self { $this->action = $action; return $this; }
    public function getSubjectType(): string { return $this->subjectType; }
    public function setSubjectType(string $type): self { $this->subjectType = $type; return $this; }
    public function getSubjectId(): ?int { return $this->subjectId; }
    public function setSubjectId(?int $id): self { $this->subjectId = $id; return $this; }
    public function getSummary(): string { return $this->summary; }
    public function setSummary(string $summary): self { $this->summary = $summary; return $this; }
    /** @return array<string, mixed> */
    public function getContext(): array { return $this->context; }
    /** @param array<string, mixed> $context */
    public function setContext(array $context): self { $this->context = $context; return $this; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ip): self { $this->ipAddress = $ip; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

<?php

declare(strict_types=1);
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class PageLayout
{
    #[ORM\Id]
    #[ORM\Column(length: 80)]
    private string $context;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $document = [];

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $context) { $this->context = $context; $this->updatedAt = new \DateTimeImmutable(); }
    public function getContext(): string { return $this->context; }
    public function getVersion(): int { return $this->version; }
    /** @return array<string, mixed> */
    public function getDocument(): array { return $this->document; }
    /** @param array<string, mixed> $document */
    public function replace(array $document): void { $this->document = $document; $this->updatedAt = new \DateTimeImmutable(); }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}

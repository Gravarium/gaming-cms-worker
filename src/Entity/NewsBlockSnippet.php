<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NewsBlockSnippetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NewsBlockSnippetRepository::class)]
#[ORM\Table(name: 'news_block_snippet')]
#[ORM\Index(name: 'idx_news_block_snippet_owner_updated', columns: ['owner_id', 'updated_at'])]
class NewsBlockSnippet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 80)]
    private string $label;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $block;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @param array<string, mixed> $block */
    public function __construct(User $owner, string $label, array $block)
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 80 || preg_match('/[\x00-\x1f\x7f]/u', $label) === 1) {
            throw new \InvalidArgumentException('Der Vorlagenname ist ungültig.');
        }
        if ($block === []) {
            throw new \InvalidArgumentException('Die Blockvorlage darf nicht leer sein.');
        }
        $this->owner = $owner;
        $this->label = $label;
        $this->block = $block;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOwner(): User { return $this->owner; }
    public function getLabel(): string { return $this->label; }
    /** @return array<string, mixed> */
    public function getBlock(): array { return $this->block; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}

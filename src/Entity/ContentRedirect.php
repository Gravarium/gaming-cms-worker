<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContentRedirectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContentRedirectRepository::class)]
#[ORM\Table(name: 'content_redirect')]
#[ORM\UniqueConstraint(name: 'uniq_content_redirect_source', columns: ['type', 'source_slug'])]
class ContentRedirect
{
    private const MAX_TYPE_BYTES = 20;
    private const MAX_SOURCE_SLUG_BYTES = 200;
    private const SOURCE_SLUG_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 20)]
    private string $type;
    #[ORM\Column(name: 'source_slug', length: 200)]
    private string $sourceSlug;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ContentEntry $entry;
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(ContentEntry $entry, string $type, string $sourceSlug)
    {
        if (strlen($type) > self::MAX_TYPE_BYTES
            || !in_array($type, [ContentEntry::TYPE_PAGE, ContentEntry::TYPE_NEWS], true)
        ) {
            throw new \InvalidArgumentException('Der Weiterleitungstyp ist ungültig.');
        }

        if (strlen($sourceSlug) > self::MAX_SOURCE_SLUG_BYTES
            || preg_match(self::SOURCE_SLUG_PATTERN, $sourceSlug) !== 1
        ) {
            throw new \InvalidArgumentException('Der Quell-Slug muss ein gültiger Route-Slug mit höchstens 200 Zeichen sein.');
        }

        $this->entry = $entry;
        $this->type = $type;
        $this->sourceSlug = $sourceSlug;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
    public function getSourceSlug(): string { return $this->sourceSlug; }
    public function getEntry(): ContentEntry { return $this->entry; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

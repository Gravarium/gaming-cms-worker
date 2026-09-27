<?php

declare(strict_types=1);

namespace App\Entity\Search;

use App\Repository\Search\SearchDocumentRepository;
use App\Search\SearchIndexRecord;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SearchDocumentRepository::class)]
#[ORM\Table(name: 'search_document')]
#[ORM\UniqueConstraint(name: 'uniq_search_document_source', columns: ['source_type', 'source_id'])]
#[ORM\Index(name: 'idx_search_document_module_type', columns: ['module_key', 'document_type'])]
class SearchDocument
{
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_AUTHENTICATED = 'authenticated';
    public const VISIBILITY_GUILD = 'guild';
    public const VISIBILITY_MODERATOR = 'moderator';
    public const VISIBILITY_OWNER_OR_MODERATOR = 'owner_or_moderator';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'source_type', length: 64)]
    private string $sourceType;

    #[ORM\Column(name: 'source_id')]
    private int $sourceId;

    #[ORM\Column(name: 'module_key', length: 64)]
    private string $moduleKey;

    #[ORM\Column(name: 'document_type', length: 40)]
    private string $documentType;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $excerpt;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $route;

    #[ORM\Column(length: 32)]
    private string $visibility;

    #[ORM\Column(name: 'owner_id', nullable: true)]
    private ?int $ownerId;

    #[ORM\Column(name: 'guild_id', nullable: true)]
    private ?int $guildId;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $facets;

    #[ORM\Column]
    private int $popularity;

    #[ORM\Column(name: 'recommendation_opt_out', options: ['default' => false])]
    private bool $recommendationOptOut;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    #[ORM\Column(name: 'source_updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $sourceUpdatedAt;

    #[ORM\Column(name: 'indexed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $indexedAt;

    public function __construct(SearchIndexRecord $record)
    {
        $this->sourceType = $record->sourceType;
        $this->sourceId = $record->sourceId;
        $this->moduleKey = $record->moduleKey;
        $this->documentType = $record->documentType;
        $this->title = $record->title;
        $this->body = $record->body;
        $this->excerpt = $record->excerpt;
        $this->route = $record->route;
        $this->visibility = $record->visibility;
        $this->ownerId = $record->ownerId;
        $this->guildId = $record->guildId;
        $this->facets = $record->facets;
        $this->popularity = $record->popularity;
        $this->recommendationOptOut = $record->recommendationOptOut;
        $this->fingerprint = $record->fingerprint();
        $this->sourceUpdatedAt = $record->sourceUpdatedAt;
        $this->indexedAt = new \DateTimeImmutable();
    }

    public function updateFrom(SearchIndexRecord $record): void
    {
        $this->sourceType = $record->sourceType;
        $this->sourceId = $record->sourceId;
        $this->moduleKey = $record->moduleKey;
        $this->documentType = $record->documentType;
        $this->title = $record->title;
        $this->body = $record->body;
        $this->excerpt = $record->excerpt;
        $this->route = $record->route;
        $this->visibility = $record->visibility;
        $this->ownerId = $record->ownerId;
        $this->guildId = $record->guildId;
        $this->facets = $record->facets;
        $this->popularity = $record->popularity;
        $this->recommendationOptOut = $record->recommendationOptOut;
        $this->fingerprint = $record->fingerprint();
        $this->sourceUpdatedAt = $record->sourceUpdatedAt;
        $this->indexedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSourceType(): string { return $this->sourceType; }
    public function getSourceId(): int { return $this->sourceId; }
    public function getModuleKey(): string { return $this->moduleKey; }
    public function getDocumentType(): string { return $this->documentType; }
    public function getTitle(): string { return $this->title; }
    public function getBody(): string { return $this->body; }
    public function getExcerpt(): ?string { return $this->excerpt; }
    public function getRoute(): ?string { return $this->route; }
    public function getVisibility(): string { return $this->visibility; }
    public function getOwnerId(): ?int { return $this->ownerId; }
    public function getGuildId(): ?int { return $this->guildId; }
    /** @return list<string> */
    public function getFacets(): array { return $this->facets; }
    public function getPopularity(): int { return $this->popularity; }
    public function isRecommendationOptOut(): bool { return $this->recommendationOptOut; }
    public function getFingerprint(): string { return $this->fingerprint; }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return $this->sourceUpdatedAt; }
    public function getIndexedAt(): \DateTimeImmutable { return $this->indexedAt; }
}

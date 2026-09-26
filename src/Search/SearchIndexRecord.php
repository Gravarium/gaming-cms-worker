<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Search\SearchDocument;

final readonly class SearchIndexRecord
{
    /** @param list<string> $facets */
    public function __construct(
        public string $sourceType,
        public int $sourceId,
        public string $moduleKey,
        public string $documentType,
        public string $title,
        public string $body,
        public ?string $excerpt,
        public ?string $route,
        public string $visibility,
        public ?int $ownerId,
        public ?int $guildId,
        public array $facets,
        public int $popularity,
        public bool $recommendationOptOut,
        public \DateTimeImmutable $sourceUpdatedAt,
    ) {
        if ($this->sourceId < 1 || $this->sourceType === '' || $this->moduleKey === '' || $this->documentType === '') {
            throw new \InvalidArgumentException('Search documents require a positive source and bounded module identity.');
        }
        if ($this->title === '' || trim($this->title) === '' || mb_strlen($this->title) > 255 || $this->body === '' || trim($this->body) === '') {
            throw new \InvalidArgumentException('Search documents require bounded searchable content.');
        }
        if (!in_array($this->visibility, [
            SearchDocument::VISIBILITY_PUBLIC,
            SearchDocument::VISIBILITY_AUTHENTICATED,
            SearchDocument::VISIBILITY_GUILD,
            SearchDocument::VISIBILITY_MODERATOR,
            SearchDocument::VISIBILITY_OWNER_OR_MODERATOR,
        ], true)) {
            throw new \InvalidArgumentException('Unknown search visibility boundary.');
        }
        if ($this->ownerId !== null && $this->ownerId < 1) {
            throw new \InvalidArgumentException('Search owner ids must be positive.');
        }
        if ($this->guildId !== null && $this->guildId < 1) {
            throw new \InvalidArgumentException('Search guild ids must be positive.');
        }
        if ($this->route !== null && (
            !str_starts_with($this->route, '/')
            || str_starts_with($this->route, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $this->route) === 1
        )) {
            throw new \InvalidArgumentException('Search result routes must be local paths.');
        }
        if ($this->popularity < 0) {
            throw new \InvalidArgumentException('Search popularity cannot be negative.');
        }
        foreach ($this->facets as $facet) {
            if ($facet === '' || mb_strlen($facet) > 100) {
                throw new \InvalidArgumentException('Search facets must be non-empty and bounded.');
            }
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            $this->sourceType,
            $this->sourceId,
            $this->moduleKey,
            $this->documentType,
            $this->title,
            $this->body,
            $this->excerpt,
            $this->route,
            $this->visibility,
            $this->ownerId,
            $this->guildId,
            $this->facets,
            $this->popularity,
            $this->recommendationOptOut,
            $this->sourceUpdatedAt->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}

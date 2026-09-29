<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SearchFilters
{
    public function __construct(
        public ?string $moduleKey = null,
        public ?string $documentType = null,
    ) {
        if ($this->moduleKey !== null && !in_array($this->moduleKey, ['content', 'gaming', 'video'], true)) {
            throw new \InvalidArgumentException('Das Suchmodul ist ungültig.');
        }
        if ($this->documentType !== null && !preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $this->documentType)) {
            throw new \InvalidArgumentException('Der Suchfilter ist ungültig.');
        }
    }

    public static function fromRequest(?string $moduleKey, ?string $documentType): self
    {
        $moduleKey = trim((string) $moduleKey);
        $documentType = trim((string) $documentType);

        return new self(
            $moduleKey === '' ? null : $moduleKey,
            $documentType === '' ? null : $documentType,
        );
    }
}

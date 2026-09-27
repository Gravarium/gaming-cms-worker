<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SearchQuery
{
    /** @var list<string> */
    private array $terms;

    /** @param list<string> $terms */
    private function __construct(public string $raw, array $terms)
    {
        $this->terms = $terms;
    }

    public static function fromString(string $input): self
    {
        $input = trim($input);
        if ($input === '' || mb_strlen($input) > 100 || preg_match('/[\x00-\x1F\x7F]/', $input) === 1) {
            throw new \InvalidArgumentException('Die Suche benötigt 2 bis 100 druckbare Zeichen.');
        }

        $normalized = preg_replace('/[^\p{L}\p{N}_-]+/u', ' ', mb_strtolower($input));
        if (!is_string($normalized)) {
            throw new \InvalidArgumentException('Der Suchbegriff ist ungültig.');
        }
        $normalized = trim($normalized);
        $terms = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($terms) || $terms === [] || mb_strlen(implode('', $terms)) < 2 || count($terms) > 8) {
            throw new \InvalidArgumentException('Die Suche benötigt einen gültigen Begriff mit höchstens 8 Wörtern.');
        }

        /** @var list<string> $uniqueTerms */
        $uniqueTerms = array_values(array_unique($terms));

        return new self($normalized, $uniqueTerms);
    }

    /** @return list<string> */
    public function terms(): array
    {
        return $this->terms;
    }
}

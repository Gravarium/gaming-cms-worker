<?php

declare(strict_types=1);

namespace App\ContentRedirect;

use App\Entity\ContentRedirect;
use InvalidArgumentException;

final class ContentRedirectChainAnalyzer
{
    private const SLUG_PATTERN = '/\\A[a-z0-9]+(?:-[a-z0-9]+)*\\z/';

    /**
     * @param iterable<ContentRedirect> $redirects
     * @return list<array{type: string, source_slug: string, path: list<string>, cycle: bool}>
     */
    public function analyze(iterable $redirects): array
    {
        $graph = [];
        foreach ($redirects as $redirect) {
            $type = $redirect->getType();
            $source = $redirect->getSourceSlug();
            $target = $redirect->getEntry()->getSlug();
            if (!in_array($type, ['news', 'page'], true)
                || preg_match(self::SLUG_PATTERN, $source) !== 1
                || preg_match(self::SLUG_PATTERN, $target) !== 1
            ) {
                throw new InvalidArgumentException('Ein Redirect enthält einen ungültigen Typ oder Slug.');
            }
            if (isset($graph[$type][$source]) && $graph[$type][$source] !== $target) {
                throw new InvalidArgumentException(sprintf('Mehrere Ziele für /%s/%s gefunden.', $type, $source));
            }
            $graph[$type][$source] = $target;
        }

        ksort($graph, SORT_STRING);
        $findings = [];
        foreach ($graph as $type => $mappings) {
            ksort($mappings, SORT_STRING);
            foreach ($mappings as $source => $_target) {
                $path = [$source];
                $visited = [];
                $current = $source;
                $cycle = false;
                while (isset($mappings[$current])) {
                    if (isset($visited[$current])) {
                        $path[] = $current;
                        $cycle = true;
                        break;
                    }
                    $visited[$current] = true;
                    $current = $mappings[$current];
                    $path[] = $current;
                    if (!isset($mappings[$current])) {
                        break;
                    }
                }

                if (count($path) > 2 || $cycle) {
                    $findings[] = [
                        'type' => $type,
                        'source_slug' => $source,
                        'path' => $path,
                        'cycle' => $cycle,
                    ];
                }
            }
        }

        return $findings;
    }
}

<?php

declare(strict_types=1);

namespace App\ContentRedirect;

use App\Entity\ContentRedirect;

final class ContentRedirectTargetAudit
{
    /**
     * @param iterable<ContentRedirect> $redirects
     * @return list<array{type: string, source_slug: string, target_slug: string}>
     */
    public function findUnpublishedTargets(iterable $redirects): array
    {
        $findings = [];
        foreach ($redirects as $redirect) {
            $target = $redirect->getEntry();
            if (!$target->isPublished()) {
                $findings[] = [
                    'type' => $redirect->getType(),
                    'source_slug' => $redirect->getSourceSlug(),
                    'target_slug' => $target->getSlug(),
                ];
            }
        }

        usort($findings, static fn (array $left, array $right): int =>
            [$left['type'], $left['source_slug'], $left['target_slug']]
            <=> [$right['type'], $right['source_slug'], $right['target_slug']]
        );

        return $findings;
    }
}

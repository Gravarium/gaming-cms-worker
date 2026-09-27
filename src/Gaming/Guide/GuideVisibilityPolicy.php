<?php

declare(strict_types=1);

namespace App\Gaming\Guide;

final class GuideVisibilityPolicy
{
    public function canRead(bool $moduleEnabled, string $reviewStatus, bool $isAuthorOrReviewer): bool
    {
        return $moduleEnabled && ($reviewStatus === 'published' || $isAuthorOrReviewer);
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Keeps public video-library slug filters within their persisted column limits.
 */
final class VideoIndexFilterPolicy
{
    private const CATEGORY_SLUG_MAX_LENGTH = 140;
    private const PLAYLIST_SLUG_MAX_LENGTH = 180;

    public static function acceptsCategorySlug(string $slug): bool
    {
        return self::hasAtMostCharacters($slug, self::CATEGORY_SLUG_MAX_LENGTH);
    }

    public static function acceptsPlaylistSlug(string $slug): bool
    {
        return self::hasAtMostCharacters($slug, self::PLAYLIST_SLUG_MAX_LENGTH);
    }

    private static function hasAtMostCharacters(string $value, int $maximum): bool
    {
        return mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') <= $maximum;
    }
}

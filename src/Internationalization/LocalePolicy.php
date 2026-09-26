<?php

declare(strict_types=1);

namespace App\Internationalization;

final class LocalePolicy
{
    public const COOKIE = 'cms_locale';

    private const MAX_ENABLED_LOCALE_ENTRIES = 64;
    private const MAX_LOCALE_TAG_BYTES = 16;

    /** @var array<string, string> */
    public const SUPPORTED = [
        'de' => 'Deutsch',
        'en' => 'English',
        'fr' => 'Français',
        'es' => 'Español',
        'it' => 'Italiano',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'pt' => 'Português',
        'cs' => 'Čeština',
        'da' => 'Dansk',
        'sv' => 'Svenska',
        'no' => 'Norsk',
        'fi' => 'Suomi',
        'tr' => 'Türkçe',
        'uk' => 'Українська',
        'ja' => '日本語',
        'ko' => '한국어',
        'zh' => '中文',
    ];

    /**
     * @param array<array-key, mixed> $enabled
     * @return non-empty-list<string>
     */
    public function normalizeEnabled(array $enabled): array
    {
        if (!array_is_list($enabled) || count($enabled) > self::MAX_ENABLED_LOCALE_ENTRIES) {
            return ['de'];
        }

        $normalized = [];
        foreach ($enabled as $locale) {
            if (
                !is_string($locale)
                || strlen($locale) > self::MAX_LOCALE_TAG_BYTES
                || !isset(self::SUPPORTED[$locale])
            ) {
                continue;
            }

            $normalized[$locale] = $locale;
        }

        return $normalized === [] ? ['de'] : array_values($normalized);
    }

    /** @param array<array-key, mixed> $enabled */
    public function choose(string $candidate, array $enabled, string $fallback): string
    {
        $enabled = $this->normalizeEnabled($enabled);

        return in_array($candidate, $enabled, true)
            ? $candidate
            : (in_array($fallback, $enabled, true) ? $fallback : $enabled[0]);
    }
}

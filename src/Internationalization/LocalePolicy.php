<?php

declare(strict_types=1);

namespace App\Internationalization;

final class LocalePolicy
{
    public const COOKIE = 'cms_locale';

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

    /** @param list<string> $enabled
     * @return non-empty-list<string>
     */
    public function normalizeEnabled(array $enabled): array
    {
        $enabled = array_values(array_unique(array_filter(
            $enabled,
            static fn (string $locale): bool => isset(self::SUPPORTED[$locale]),
        )));

        return $enabled === [] ? ['de'] : $enabled;
    }

    /** @param list<string> $enabled */
    public function choose(string $candidate, array $enabled, string $fallback): string
    {
        $enabled = $this->normalizeEnabled($enabled);

        return in_array($candidate, $enabled, true)
            ? $candidate
            : (in_array($fallback, $enabled, true) ? $fallback : $enabled[0]);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Internationalization;

use App\Internationalization\LocalePolicy;
use PHPUnit\Framework\TestCase;

final class LocalePolicyTest extends TestCase
{
    public function testNormalizesUnknownAndDuplicateLocales(): void
    {
        self::assertSame(['de', 'en'], (new LocalePolicy())->normalizeEnabled(['de', 'xx', 'en', 'de']));
    }

    public function testIgnoresNonStringAndUnsupportedPersistedCandidates(): void
    {
        self::assertSame(
            ['de', 'fr', 'en'],
            (new LocalePolicy())->normalizeEnabled(['de', 7, null, ['en'], 'xx', 'fr', 'en', 'de']),
        );
    }

    public function testBoundsCollectionShapeAndLocaleTagLength(): void
    {
        $policy = new LocalePolicy();

        self::assertSame(['de'], $policy->normalizeEnabled(['primary' => 'en']));
        self::assertSame(['de'], $policy->normalizeEnabled(array_fill(0, 65, 'en')));
        self::assertSame(['en'], $policy->normalizeEnabled([str_repeat('x', 17), 'en']));
    }

    public function testNeverReturnsAnUnsupportedLocale(): void
    {
        $policy = new LocalePolicy();

        self::assertSame('en', $policy->choose('xx', ['de', 'en'], 'en'));
        self::assertSame('de', $policy->choose('xx', ['de'], 'xx'));
        self::assertSame('de', $policy->choose('xx', [], 'xx'));
        self::assertSame('en', $policy->choose('en', ['de', false, 'en'], 'de'));
    }
}

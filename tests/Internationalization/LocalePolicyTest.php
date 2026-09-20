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

    public function testNeverReturnsAnUnsupportedLocale(): void
    {
        $policy = new LocalePolicy();

        self::assertSame('en', $policy->choose('xx', ['de', 'en'], 'en'));
        self::assertSame('de', $policy->choose('xx', ['de'], 'xx'));
        self::assertSame('de', $policy->choose('xx', [], 'xx'));
    }
}

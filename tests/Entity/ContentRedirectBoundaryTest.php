<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ContentEntry;
use App\Entity\ContentRedirect;
use PHPUnit\Framework\TestCase;

final class ContentRedirectBoundaryTest extends TestCase
{
    public function testAcceptsSupportedTypesAndMaximumRouteSafeSourceSlug(): void
    {
        $sourceSlug = str_repeat('a', 200);

        foreach ([ContentEntry::TYPE_PAGE, ContentEntry::TYPE_NEWS] as $type) {
            $redirect = new ContentRedirect(new ContentEntry(), $type, $sourceSlug);

            self::assertSame($type, $redirect->getType());
            self::assertSame($sourceSlug, $redirect->getSourceSlug());
        }
    }

    public function testRejectsUnsupportedOrOversizedTypes(): void
    {
        foreach (['', 'video', str_repeat('x', 21)] as $type) {
            try {
                new ContentRedirect(new ContentEntry(), $type, 'valid-source');
                self::fail('An unsupported redirect type must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRejectsEmptyMalformedAndPathLikeSourceSlugs(): void
    {
        foreach ([
            '',
            'Uppercase',
            'contains/slash',
            'contains..dots',
            '-leading-hyphen',
            'trailing-hyphen-',
            'double--hyphen',
            "invalid\xFFutf8",
        ] as $sourceSlug) {
            try {
                new ContentRedirect(new ContentEntry(), ContentEntry::TYPE_PAGE, $sourceSlug);
                self::fail('A malformed redirect source slug must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRejectsSourceSlugLongerThanTheDatabaseColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ContentRedirect(new ContentEntry(), ContentEntry::TYPE_NEWS, str_repeat('a', 201));
    }
}

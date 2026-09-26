<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PageLayout;
use PHPUnit\Framework\TestCase;

final class PageLayoutBoundaryTest extends TestCase
{
    public function testContextPreservesExactAsciiAndMultibyteColumnBoundaries(): void
    {
        $asciiContext = str_repeat('x', 80);
        self::assertSame($asciiContext, (new PageLayout($asciiContext))->getContext());

        $multibyteContext = str_repeat('🎮', 80);
        self::assertSame(320, strlen($multibyteContext));
        self::assertSame($multibyteContext, (new PageLayout($multibyteContext))->getContext());
    }

    public function testConstructorRejectsOversizedAndMalformedContexts(): void
    {
        foreach ([str_repeat('x', 81), str_repeat('é', 81), str_repeat(' ', 321), "invalid\xFFutf8", "page-1\0suffix"] as $context) {
            $this->assertRejected(static function () use ($context): void {
                new PageLayout($context);
            });
        }
    }

    public function testExistingPageContextIdentityRemainsUnchanged(): void
    {
        self::assertSame('home', (new PageLayout('home'))->getContext());
        self::assertSame('page-123', (new PageLayout('page-123'))->getContext());
    }

    /** @param \Closure(): mixed $operation */
    private function assertRejected(\Closure $operation): void
    {
        try {
            $operation();
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);

            return;
        }

        self::fail('An out-of-bound layout context must be rejected.');
    }
}

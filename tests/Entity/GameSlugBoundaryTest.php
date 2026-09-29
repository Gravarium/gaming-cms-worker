<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Game;
use PHPUnit\Framework\TestCase;

final class GameSlugBoundaryTest extends TestCase
{
    public function testSlugAcceptsExactAsciiAndMultibyteColumnBoundaries(): void
    {
        $asciiSlug = str_repeat('g', 140);
        self::assertSame($asciiSlug, (new Game())->setSlug($asciiSlug)->getSlug());

        $multibyteSlug = str_repeat('🎮', 140);
        self::assertSame(560, strlen($multibyteSlug));
        self::assertSame($multibyteSlug, (new Game())->setSlug($multibyteSlug)->getSlug());
    }

    public function testRejectedSlugsDoNotReplaceTheStoredValue(): void
    {
        $state = (new Game())->setSlug('original-slug');

        foreach ([str_repeat('g', 141), str_repeat('é', 141), str_repeat(' ', 561), "invalid\xFFutf8", "game\0suffix"] as $candidate) {
            $this->assertRejected(static function () use ($state, $candidate): void {
                $state->setSlug($candidate);
            });

            self::assertSame('original-slug', $state->getSlug());
        }
    }

    public function testSlugSetterPreservesExistingNormalizationSemantics(): void
    {
        $slug = 'game-with-dash';
        self::assertSame($slug, (new Game())->setSlug($slug)->getSlug());
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

        self::fail('An out-of-bound game slug must be rejected.');
    }
}

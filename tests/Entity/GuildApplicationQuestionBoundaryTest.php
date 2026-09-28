<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GuildApplicationQuestion;
use PHPUnit\Framework\TestCase;

final class GuildApplicationQuestionBoundaryTest extends TestCase
{
    public function testLabelAcceptsExactAsciiAndMultibyteColumnBoundaries(): void
    {
        $asciiLabel = str_repeat('Q', 255);
        self::assertSame($asciiLabel, (new GuildApplicationQuestion())->setLabel($asciiLabel)->getLabel());

        $multibyteLabel = str_repeat('🎮', 255);
        self::assertSame(1020, strlen($multibyteLabel));
        self::assertSame($multibyteLabel, (new GuildApplicationQuestion())->setLabel($multibyteLabel)->getLabel());
    }

    public function testRejectedLabelsDoNotReplaceTheStoredValue(): void
    {
        $state = (new GuildApplicationQuestion())->setLabel('Question');

        foreach ([str_repeat('Q', 256), str_repeat('é', 256), str_repeat(' ', 1021), "invalid\xFFutf8", "Question\0suffix"] as $candidate) {
            $this->assertRejected(static function () use ($state, $candidate): void {
                $state->setLabel($candidate);
            });

            self::assertSame('Question', $state->getLabel());
        }
    }

    public function testLabelKeepsItsCurrentTrimmingBehavior(): void
    {
        $state = new GuildApplicationQuestion();

        self::assertSame('Favorite class', $state->setLabel('  Favorite class  ')->getLabel());
        self::assertSame('', $state->setLabel('   ')->getLabel());
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

        self::fail('An out-of-bound application question label must be rejected.');
    }
}

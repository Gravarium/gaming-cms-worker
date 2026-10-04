<?php

declare(strict_types=1);

namespace App\Tests\CompetitionScheduling;

use App\CompetitionScheduling\MatchScheduleInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MatchScheduleInputTest extends TestCase
{
    public function testLocalUtcAndOffsetInputsRepresentTheSameInstant(): void
    {
        $parser = new MatchScheduleInput();
        $now = new \DateTimeImmutable('2026-10-01T11:00:00Z');

        foreach (['2026-10-01T12:30', '2026-10-01T12:30Z', '2026-10-01T12:30:00+00:00', '2026-10-01T14:30+02:00'] as $raw) {
            $parsed = $parser->parse($raw, $now);
            self::assertSame('2026-10-01T12:30:00+00:00', $parsed->format(\DateTimeInterface::ATOM));
            self::assertSame('UTC', $parsed->getTimezone()->getName());
        }
    }

    #[DataProvider('invalidInput')]
    public function testInvalidInputIsRejected(string $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MatchScheduleInput())->parse($raw, new \DateTimeImmutable('2026-10-01T11:00:00Z'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInput(): iterable
    {
        yield 'empty' => [''];
        yield 'relative' => ['tomorrow'];
        yield 'invalid date' => ['2026-02-30T12:30'];
        yield 'invalid hour' => ['2026-10-01T25:30'];
        yield 'past' => ['2026-10-01T10:54'];
        yield 'too long' => [str_repeat('a', 33)];
        yield 'script' => ['<script>alert(1)</script>'];
        yield 'missing time' => ['2026-10-01'];
    }
}

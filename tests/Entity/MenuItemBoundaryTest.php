<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MenuItem;
use PHPUnit\Framework\TestCase;

final class MenuItemBoundaryTest extends TestCase
{
    public function testPreservesWhitespaceNormalizationForBoundedValues(): void
    {
        $item = (new MenuItem())
            ->setLabel(' Navigation ')
            ->setUrl(' https://example.invalid/path ');

        self::assertSame('Navigation', $item->getLabel());
        self::assertSame('https://example.invalid/path', $item->getUrl());
        self::assertNull($item->setUrl('   ')->getUrl());
    }

    public function testAcceptsExactColumnCharacterBoundaries(): void
    {
        $label = str_repeat('🛡', 100);
        $urlPrefix = 'https://example.invalid/';
        $url = $urlPrefix.str_repeat('x', 500 - mb_strlen($urlPrefix, 'UTF-8'));

        $item = (new MenuItem())
            ->setLabel($label)
            ->setUrl($url);

        self::assertSame($label, $item->getLabel());
        self::assertSame($url, $item->getUrl());
        self::assertSame(400, strlen($label));
        self::assertSame(100, mb_strlen($label, 'UTF-8'));
        self::assertSame(500, mb_strlen($url, 'UTF-8'));
    }

    public function testRejectsValuesBeyondCharacterLimitsWithoutReplacingPreviousValues(): void
    {
        $urlPrefix = 'https://example.invalid/';
        $item = (new MenuItem())->setLabel('Saved')->setUrl($urlPrefix.'saved');

        $this->assertLengthRejectedWithoutMutation(
            fn () => $item->setLabel(str_repeat('x', 101)),
            fn () => $item->getLabel(),
            'Saved',
        );
        $this->assertLengthRejectedWithoutMutation(
            fn () => $item->setUrl($urlPrefix.str_repeat('x', 501 - mb_strlen($urlPrefix, 'UTF-8'))),
            fn () => $item->getUrl(),
            $urlPrefix.'saved',
        );
    }

    public function testRejectsOversizedBytesBeforeTrimmingOrUrlParsing(): void
    {
        $urlPrefix = 'https://example.invalid/';
        $item = (new MenuItem())->setLabel('Saved')->setUrl($urlPrefix.'saved');

        try {
            $item->setLabel(str_repeat('x', 401));
            self::fail('Oversized label bytes were accepted.');
        } catch (\LengthException $exception) {
            self::assertStringContainsString('byte limit', $exception->getMessage());
        }
        self::assertSame('Saved', $item->getLabel());

        $oversizedUrl = $urlPrefix.str_repeat('x', 2001 - strlen($urlPrefix));
        try {
            $item->setUrl($oversizedUrl);
            self::fail('Oversized URL bytes were accepted.');
        } catch (\LengthException $exception) {
            self::assertStringContainsString('byte limit', $exception->getMessage());
        }
        self::assertSame($urlPrefix.'saved', $item->getUrl());
    }

    public function testRejectsMalformedUtf8NulBytesAndKeepsUrlPolicy(): void
    {
        $item = (new MenuItem())->setLabel('Saved')->setUrl('https://example.invalid/saved');

        try {
            $item->setLabel("\xFF");
            self::fail('Malformed UTF-8 was accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('Saved', $item->getLabel());

        try {
            $item->setUrl("https://example.invalid/\0path");
            self::fail('A NUL byte was accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('https://example.invalid/saved', $item->getUrl());

        foreach ([
            'javascript:alert(1)',
            'data:text/html,test',
            'https://user:secret@example.invalid/path',
            '//example.invalid/path',
        ] as $url) {
            try {
                $item->setUrl($url);
                self::fail('Unsafe menu URL accepted: '.$url);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('https://example.invalid/saved', $item->getUrl());
    }

    private function assertLengthRejectedWithoutMutation(callable $change, callable $read, mixed $expected): void
    {
        try {
            $change();
            self::fail('An over-limit menu value was accepted.');
        } catch (\LengthException) {
            self::addToAssertionCount(1);
        }

        self::assertSame($expected, $read());
    }
}

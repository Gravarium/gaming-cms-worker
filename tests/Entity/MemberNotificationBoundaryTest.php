<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MemberNotification;
use PHPUnit\Framework\TestCase;

final class MemberNotificationBoundaryTest extends TestCase
{
    public function testPreservesInBoundTextAndLocalLinkNormalization(): void
    {
        $type = ' guild_event ';
        $title = ' Notice ';
        $message = " Message\nbody ";
        $notification = (new MemberNotification())
            ->setType($type)
            ->setTitle($title)
            ->setMessage($message)
            ->setLink(' /guild-area/1 ');

        self::assertSame($type, $notification->getType());
        self::assertSame($title, $notification->getTitle());
        self::assertSame($message, $notification->getMessage());
        self::assertSame('/guild-area/1', $notification->getLink());
    }

    public function testAcceptsExactPersistedLimitsWithFourByteUtf8Characters(): void
    {
        $type = str_repeat('🛡', 60);
        $title = str_repeat('🛡', 180);
        $message = str_repeat('🛡', 4000);
        $link = '/'.str_repeat('🛡', 499);

        $notification = (new MemberNotification())
            ->setType($type)
            ->setTitle($title)
            ->setMessage($message)
            ->setLink($link);

        self::assertSame($type, $notification->getType());
        self::assertSame($title, $notification->getTitle());
        self::assertSame($message, $notification->getMessage());
        self::assertSame($link, $notification->getLink());
        self::assertSame(240, strlen($type));
        self::assertSame(720, strlen($title));
        self::assertSame(16000, strlen($message));
        self::assertSame(500, mb_strlen($link, 'UTF-8'));
    }

    public function testRejectsValuesBeyondCharacterLimitsWithoutReplacingPreviousValues(): void
    {
        $notification = (new MemberNotification())
            ->setType('guild_event')
            ->setTitle('Notice')
            ->setMessage('Message')
            ->setLink('/guild-area/1');

        $this->assertLengthRejectedWithoutMutation(
            fn () => $notification->setType(str_repeat('t', 61)),
            fn () => $notification->getType(),
            'guild_event',
        );
        $this->assertLengthRejectedWithoutMutation(
            fn () => $notification->setTitle(str_repeat('t', 181)),
            fn () => $notification->getTitle(),
            'Notice',
        );
        $this->assertLengthRejectedWithoutMutation(
            fn () => $notification->setMessage(str_repeat('m', 4001)),
            fn () => $notification->getMessage(),
            'Message',
        );
        $this->assertLengthRejectedWithoutMutation(
            fn () => $notification->setLink('/'.str_repeat('a', 500)),
            fn () => $notification->getLink(),
            '/guild-area/1',
        );
    }

    public function testRejectsOversizedBytesBeforeCharacterCounting(): void
    {
        $notification = new MemberNotification();

        try {
            $notification->setMessage(str_repeat('x', 16001));
            self::fail('Oversized member notification bytes were accepted.');
        } catch (\LengthException $exception) {
            self::assertStringContainsString('byte limit', $exception->getMessage());
        }

        self::assertSame('', $notification->getMessage());
    }

    public function testRejectsMalformedUtf8NulBytesAndUnsafeLinks(): void
    {
        $notification = (new MemberNotification())->setTitle('kept');

        try {
            $notification->setTitle("\xFF");
            self::fail('Malformed UTF-8 was accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('kept', $notification->getTitle());

        try {
            $notification->setMessage("unsafe\0message");
            self::fail('A NUL byte was accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('', $notification->getMessage());

        try {
            $notification->setLink('//external.example.invalid');
            self::fail('An external member notification link was accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertNull($notification->getLink());
    }

    private function assertLengthRejectedWithoutMutation(callable $change, callable $read, mixed $expected): void
    {
        try {
            $change();
            self::fail('An over-limit member notification value was accepted.');
        } catch (\LengthException) {
            self::addToAssertionCount(1);
        }

        self::assertSame($expected, $read());
    }
}

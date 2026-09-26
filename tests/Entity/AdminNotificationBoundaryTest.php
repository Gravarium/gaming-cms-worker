<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AdminNotification;
use PHPUnit\Framework\TestCase;

final class AdminNotificationBoundaryTest extends TestCase
{
    public function testPreservesTrimmingAndSafeLocalLinkSemantics(): void
    {
        $notification = (new AdminNotification())
            ->setType(' system ')
            ->setTitle(' Notice ')
            ->setMessage(' Message body ')
            ->setLink(' /admin/notifications ');

        self::assertSame('system', $notification->getType());
        self::assertSame('Notice', $notification->getTitle());
        self::assertSame('Message body', $notification->getMessage());
        self::assertSame('/admin/notifications', $notification->getLink());

        self::assertNull($notification->setLink(null)->getLink());
        self::assertNull($notification->setLink('   ')->getLink());
    }

    public function testAcceptsExactPersistedLimitsWithFourByteUtf8Characters(): void
    {
        $type = str_repeat('🛡', 60);
        $title = str_repeat('🛡', 180);
        $message = str_repeat('🛡', 4000);
        $link = '/'.str_repeat('🛡', 499);

        $notification = (new AdminNotification())
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
        $notification = (new AdminNotification())
            ->setType('system')
            ->setTitle('Notice')
            ->setMessage('Message')
            ->setLink('/admin');

        $this->assertLengthRejectedWithoutMutation(
            fn () => $notification->setType(str_repeat('t', 61)),
            fn () => $notification->getType(),
            'system',
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
            '/admin',
        );
    }

    public function testRejectsOversizedBytesBeforeCharacterCounting(): void
    {
        $notification = new AdminNotification();

        try {
            $notification->setMessage(str_repeat('x', 16001));
            self::fail('Oversized notification bytes were accepted.');
        } catch (\LengthException $exception) {
            self::assertStringContainsString('byte limit', $exception->getMessage());
        }

        self::assertSame('', $notification->getMessage());
    }

    public function testRejectsMalformedUtf8NulBytesAndUnsafeLinks(): void
    {
        $notification = (new AdminNotification())->setTitle('kept');

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
            self::fail('An external notification link was accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertNull($notification->getLink());
    }

    private function assertLengthRejectedWithoutMutation(callable $change, callable $read, mixed $expected): void
    {
        try {
            $change();
            self::fail('An over-limit notification value was accepted.');
        } catch (\LengthException) {
            self::addToAssertionCount(1);
        }

        self::assertSame($expected, $read());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\ExternalConnector\ExternalNotificationMessage;
use PHPUnit\Framework\TestCase;

final class ExternalNotificationMessageTest extends TestCase
{
    public function testNormalizesValidRoutingAndNotificationValues(): void
    {
        $message = new ExternalNotificationMessage(
            ' Guild_Event ',
            '  Neuer Raid  ',
            "Beginn:\nHeute um 20:00 Uhr",
            ' /guild-area/12 ',
            ' guild:12 ',
        );

        self::assertSame('guild_event', $message->type);
        self::assertSame('Neuer Raid', $message->title);
        self::assertSame("Beginn:\nHeute um 20:00 Uhr", $message->message);
        self::assertSame('/guild-area/12', $message->link);
        self::assertSame('guild:12', $message->recipientReference);
    }

    public function testEmptyOptionalValuesNormalizeToNull(): void
    {
        $message = new ExternalNotificationMessage('announcement', 'Title', 'Message', '  ', '');

        self::assertNull($message->link);
        self::assertNull($message->recipientReference);
    }

    public function testRejectsInvalidRoutingTypes(): void
    {
        $this->assertInvalid(static fn (): ExternalNotificationMessage => new ExternalNotificationMessage('', 'Title', 'Message'));
        $this->assertInvalid(static fn (): ExternalNotificationMessage => new ExternalNotificationMessage('guild event', 'Title', 'Message'));
        $this->assertInvalid(static fn (): ExternalNotificationMessage => new ExternalNotificationMessage(str_repeat('a', 65), 'Title', 'Message'));
        $this->assertInvalid(static fn (): ExternalNotificationMessage => new ExternalNotificationMessage("guild_event\xFF", 'Title', 'Message'));
        $this->assertInvalid(static fn (): ExternalNotificationMessage => new ExternalNotificationMessage("guild_event\x7F", 'Title', 'Message'));
    }

    public function testRejectsOversizedAndUnsafeRequiredText(): void
    {
        $this->assertInvalid(static fn (): ExternalNotificationMessage => new ExternalNotificationMessage('type', str_repeat('a', 201), 'Message'));
        $this->assertInvalid(static fn (): ExternalNotificationMessage => new ExternalNotificationMessage('type', 'Title', str_repeat('a', 4001)));
        $this->assertInvalid(static fn (): ExternalNotificationMessage => new ExternalNotificationMessage('type', "Title\x00", 'Message'));
        $this->assertInvalid(static fn (): ExternalNotificationMessage => new ExternalNotificationMessage('type', 'Title', "Message\x1B"));
    }

    public function testRejectsOversizedAndUnsafeOptionalText(): void
    {
        $this->assertInvalid(static fn (): ExternalNotificationMessage => new ExternalNotificationMessage('type', 'Title', 'Message', str_repeat('a', 501)));
        $this->assertInvalid(static fn (): ExternalNotificationMessage => new ExternalNotificationMessage('type', 'Title', 'Message', "/guild-area/\xFF"));
        $this->assertInvalid(static fn (): ExternalNotificationMessage => new ExternalNotificationMessage('type', 'Title', 'Message', null, str_repeat('a', 201)));
        $this->assertInvalid(static fn (): ExternalNotificationMessage => new ExternalNotificationMessage('type', 'Title', 'Message', null, "guild:12\n"));
    }

    private function assertInvalid(callable $factory): void
    {
        try {
            $factory();
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);

            return;
        }

        self::fail('The notification message input was accepted.');
    }
}

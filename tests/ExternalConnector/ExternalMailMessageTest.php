<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\ExternalConnector\ExternalMailMessage;
use PHPUnit\Framework\TestCase;

final class ExternalMailMessageTest extends TestCase
{
    public function testNormalizesRecipientsAndPreservesValidTextAndHtml(): void
    {
        $message = new ExternalMailMessage(
            [' member@example.invalid ', 'member@example.invalid'],
            '  Passwort zurücksetzen  ',
            "Hallo\nBitte anmelden.",
            ' <p>Hallo</p> ',
        );

        self::assertSame(['member@example.invalid'], $message->recipients);
        self::assertSame('Passwort zurücksetzen', $message->subject);
        self::assertSame("Hallo\nBitte anmelden.", $message->text);
        self::assertSame('<p>Hallo</p>', $message->html);
    }

    public function testEmptyOptionalHtmlNormalizesToNull(): void
    {
        $message = new ExternalMailMessage(['member@example.invalid'], 'Subject', 'Body', '  ');

        self::assertNull($message->html);
    }

    public function testRejectsInvalidRecipientCollectionsAndValues(): void
    {
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage([], 'Subject', 'Body'));
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage(array_fill(0, 101, 'member@example.invalid'), 'Subject', 'Body'));
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage(['not-an-email'], 'Subject', 'Body'));
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage(["member@example.invalid\r\nBcc: attacker@example.invalid"], 'Subject', 'Body'));
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage(["member\xFF@example.invalid"], 'Subject', 'Body'));
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage([str_repeat('a', 321).'@example.invalid'], 'Subject', 'Body'));
    }

    public function testRejectsUnsafeOrOversizedSubject(): void
    {
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage(['member@example.invalid'], '', 'Body'));
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage(['member@example.invalid'], str_repeat('a', 201), 'Body'));
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage(['member@example.invalid'], "Subject\r\nBcc: attacker@example.invalid", 'Body'));
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage(['member@example.invalid'], "Subject\x00", 'Body'));
    }

    public function testRejectsUnsafeOrOversizedBody(): void
    {
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage(['member@example.invalid'], 'Subject', ''));
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage(['member@example.invalid'], 'Subject', str_repeat('a', 10_001)));
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage(['member@example.invalid'], 'Subject', "Body\x00"));
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage(['member@example.invalid'], 'Subject', "Body\xFF"));
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage(['member@example.invalid'], 'Subject', "Body\r\nBcc: attacker@example.invalid"));
    }

    public function testRejectsUnsafeOrOversizedHtmlBody(): void
    {
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage(['member@example.invalid'], 'Subject', 'Body', str_repeat('a', 20_001)));
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage(['member@example.invalid'], 'Subject', 'Body', "<p>\xFF</p>"));
        $this->assertInvalid(static fn (): ExternalMailMessage => new ExternalMailMessage(['member@example.invalid'], 'Subject', 'Body', "<p>\x00</p>"));
    }

    private function assertInvalid(callable $factory): void
    {
        try {
            $factory();
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);

            return;
        }

        self::fail('The external mail input was accepted.');
    }
}

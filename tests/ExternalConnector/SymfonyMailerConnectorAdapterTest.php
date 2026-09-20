<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\ExternalConnectorTargetDefinition;
use App\ExternalConnector\ExternalMailMessage;
use App\ExternalConnector\SymfonyMailerConnectorAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class SymfonyMailerConnectorAdapterTest extends TestCase
{
    public function testSendsThroughTheExistingSymfonyTransport(): void
    {
        $mailer = new class implements MailerInterface {
            public ?RawMessage $message = null;
            public function send(RawMessage $message, ?Envelope $envelope = null): void { $this->message = $message; }
        };
        $adapter = new SymfonyMailerConnectorAdapter($mailer, 'cms@example.invalid');

        $adapter->send(
            $this->target(SymfonyMailerConnectorAdapter::CONFIGURATION_REFERENCE),
            new ExternalMailMessage(['member@example.invalid'], 'Subject', 'Text', '<p>HTML</p>'),
        );

        self::assertInstanceOf(Email::class, $mailer->message);
        self::assertSame('cms@example.invalid', $mailer->message->getFrom()[0]->getAddress());
        self::assertSame('member@example.invalid', $mailer->message->getTo()[0]->getAddress());
        self::assertSame('Subject', $mailer->message->getSubject());
        self::assertSame('Text', $mailer->message->getTextBody());
        self::assertSame('<p>HTML</p>', $mailer->message->getHtmlBody());
    }

    public function testRefusesAnUnknownServerConfigurationReference(): void
    {
        $mailer = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void {}
        };
        $adapter = new SymfonyMailerConnectorAdapter($mailer, 'cms@example.invalid');

        $this->expectException(\LogicException::class);
        $adapter->send($this->target('mailer.unknown'), new ExternalMailMessage(['member@example.invalid'], 'Subject', 'Text'));
    }

    private function target(string $reference): ExternalConnectorTargetDefinition
    {
        return new ExternalConnectorTargetDefinition(
            ExternalConnectorTarget::CAPABILITY_MAIL,
            'default-mail',
            SymfonyMailerConnectorAdapter::PROVIDER_KEY,
            'Default mail transport',
            true,
            10,
            $reference,
        );
    }
}

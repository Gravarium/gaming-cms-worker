<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\ExternalConnector\ExternalConnectorAdapterRegistry;
use App\ExternalConnector\ExternalConnectorExecutor;
use App\ExternalConnector\ExternalConnectorRegistry;
use App\ExternalConnector\ExternalConnectorTargetSource;
use App\ExternalConnector\ExternalMailDispatcher;
use App\Service\AccountMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AccountMailerTest extends TestCase
{
    public function testVerificationUsesLocalFallbackWithBoundedEscapedDisplayName(): void
    {
        $user = (new User())
            ->setEmail('player@example.test')
            ->setDisplayName(str_repeat('A', 100).'<');
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::once())
            ->method('generate')
            ->with(
                'app_verify_email',
                ['token' => 'token_123-ABC'],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            ->willReturn('https://example.test/verify?token=token_123-ABC');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (RawMessage $message): bool {
                if (!$message instanceof Email) {
                    return false;
                }

                $text = $message->getTextBody() ?? '';
                $html = $message->getHtmlBody() ?? '';

                return $message->getTo()[0]->getAddress() === 'player@example.test'
                    && !str_contains($text, str_repeat('A', 81))
                    && str_contains($html, '&lt;');
            }));

        $mailerService = new AccountMailer($mailer, $this->dispatcher(), $urls, 'noreply@example.test');
        $mailerService->sendVerification($user, 'token_123-ABC');
    }

    public function testPasswordResetRejectsMalformedTokenBeforeRouteGeneration(): void
    {
        $user = (new User())
            ->setEmail('player@example.test')
            ->setDisplayName('Player');
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::never())->method('generate');
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Token');

        (new AccountMailer($mailer, $this->dispatcher(), $urls, 'noreply@example.test'))
            ->sendPasswordReset($user, 'token/with?separators');
    }

    public function testControlCharacterInDisplayNameIsRejectedBeforeMailCreation(): void
    {
        $user = (new User())
            ->setEmail('player@example.test')
            ->setDisplayName("Player\nInjected");
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::never())->method('generate');
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Anzeigename');

        (new AccountMailer($mailer, $this->dispatcher(), $urls, 'noreply@example.test'))
            ->sendVerification($user, 'token_123-ABC');
    }

    private function dispatcher(): ExternalMailDispatcher
    {
        $source = new class implements ExternalConnectorTargetSource {
            public function enabledFor(string $capability): array
            {
                return [];
            }
        };
        $registry = new ExternalConnectorRegistry($source);

        return new ExternalMailDispatcher(
            new ExternalConnectorExecutor($registry, new ExternalConnectorAdapterRegistry([])),
        );
    }
}

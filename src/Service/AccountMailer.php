<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\ExternalConnector\ExternalConnectorExecutionSummary;
use App\ExternalConnector\ExternalMailDispatcher;
use App\ExternalConnector\ExternalMailMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AccountMailer
{
    private const MAX_TOKEN_LENGTH = 200;
    private const MAX_DISPLAY_NAME_LENGTH = 80;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly ExternalMailDispatcher $externalMail,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%env(string:MAIL_FROM_ADDRESS)%')] private readonly string $fromAddress,
    ) {}

    public function sendVerification(User $user, string $plainToken): void
    {
        $this->assertPlainToken($plainToken);
        $displayName = $this->safeDisplayName($user);
        $url = $this->urls->generate('app_verify_email', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->send(new ExternalMailMessage(
            [$user->getEmail()],
            'E-Mail-Adresse bestätigen',
            "Hallo ".$displayName.",\n\nbestätige deine E-Mail-Adresse innerhalb von 24 Stunden:\n".$url."\n\nFalls du das nicht angefordert hast, ignoriere diese Nachricht.",
            '<p>Hallo '.htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').',</p><p>bestätige deine E-Mail-Adresse innerhalb von 24 Stunden:</p><p><a href="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">E-Mail-Adresse bestätigen</a></p><p>Falls du das nicht angefordert hast, ignoriere diese Nachricht.</p>',
        ));
    }

    public function sendPasswordReset(User $user, string $plainToken): void
    {
        $this->assertPlainToken($plainToken);
        $displayName = $this->safeDisplayName($user);
        $url = $this->urls->generate('app_reset_password', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->send(new ExternalMailMessage(
            [$user->getEmail()],
            'Passwort zurücksetzen',
            "Hallo ".$displayName.",\n\nsetze dein Passwort innerhalb einer Stunde zurück:\n".$url."\n\nFalls du das nicht angefordert hast, ignoriere diese Nachricht.",
            '<p>Hallo '.htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').',</p><p>setze dein Passwort innerhalb einer Stunde zurück:</p><p><a href="'.htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">Passwort zurücksetzen</a></p><p>Falls du das nicht angefordert hast, ignoriere diese Nachricht.</p>',
        ));
    }

    private function send(ExternalMailMessage $message): void
    {
        $summary = $this->externalMail->send($message);
        if ($summary->status() === ExternalConnectorExecutionSummary::STATUS_NOT_CONFIGURED) {
            $email = (new Email())
                ->from($this->fromAddress)
                ->to(...$message->recipients)
                ->subject($message->subject)
                ->text($message->text);
            if ($message->html !== null) {
                $email->html($message->html);
            }
            $this->mailer->send($email);

            return;
        }

        if ($summary->status() === ExternalConnectorExecutionSummary::STATUS_FAILED) {
            throw new \RuntimeException('No configured external mail target completed successfully.');
        }
    }

    private function assertPlainToken(string $plainToken): void
    {
        if (
            $plainToken === ''
            || strlen($plainToken) > self::MAX_TOKEN_LENGTH
            || preg_match('/^[A-Za-z0-9_-]+$/D', $plainToken) !== 1
        ) {
            throw new \DomainException('Das Konto-Token besitzt kein gültiges Format.');
        }
    }

    private function safeDisplayName(User $user): string
    {
        $displayName = trim($user->getDisplayName());
        if ($displayName === '') {
            return 'Konto';
        }
        if (
            !mb_check_encoding($displayName, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $displayName) === 1
        ) {
            throw new \DomainException('Der Anzeigename ist für den Mailversand ungültig.');
        }

        return mb_substr($displayName, 0, self::MAX_DISPLAY_NAME_LENGTH);
    }
}

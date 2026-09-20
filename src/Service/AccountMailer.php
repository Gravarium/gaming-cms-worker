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
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly ExternalMailDispatcher $externalMail,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%env(string:MAIL_FROM_ADDRESS)%')] private readonly string $fromAddress,
    ) {}

    public function sendVerification(User $user, string $plainToken): void
    {
        $url = $this->urls->generate('app_verify_email', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->send(new ExternalMailMessage(
            [$user->getEmail()],
            'E-Mail-Adresse bestätigen',
            "Hallo ".$user->getDisplayName().",\n\nbestätige deine E-Mail-Adresse innerhalb von 24 Stunden:\n".$url."\n\nFalls du das nicht angefordert hast, ignoriere diese Nachricht.",
            '<p>Hallo '.htmlspecialchars($user->getDisplayName(), ENT_QUOTES).',</p><p>bestätige deine E-Mail-Adresse innerhalb von 24 Stunden:</p><p><a href="'.htmlspecialchars($url, ENT_QUOTES).'">E-Mail-Adresse bestätigen</a></p><p>Falls du das nicht angefordert hast, ignoriere diese Nachricht.</p>',
        ));
    }

    public function sendPasswordReset(User $user, string $plainToken): void
    {
        $url = $this->urls->generate('app_reset_password', ['token' => $plainToken], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->send(new ExternalMailMessage(
            [$user->getEmail()],
            'Passwort zurücksetzen',
            "Hallo ".$user->getDisplayName().",\n\nsetze dein Passwort innerhalb einer Stunde zurück:\n".$url."\n\nFalls du das nicht angefordert hast, ignoriere diese Nachricht.",
            '<p>Hallo '.htmlspecialchars($user->getDisplayName(), ENT_QUOTES).',</p><p>setze dein Passwort innerhalb einer Stunde zurück:</p><p><a href="'.htmlspecialchars($url, ENT_QUOTES).'">Passwort zurücksetzen</a></p><p>Falls du das nicht angefordert hast, ignoriere diese Nachricht.</p>',
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
}

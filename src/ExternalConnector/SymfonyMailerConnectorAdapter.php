<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class SymfonyMailerConnectorAdapter implements ExternalMailConnectorAdapter
{
    public const PROVIDER_KEY = 'symfony-mailer';
    public const CONFIGURATION_REFERENCE = 'mailer.default';

    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(string:MAIL_FROM_ADDRESS)%')]
        private string $fromAddress,
    ) {
    }

    public function providerKey(): string
    {
        return self::PROVIDER_KEY;
    }

    public function supports(string $capability): bool
    {
        return $capability === ExternalConnectorTarget::CAPABILITY_MAIL;
    }

    public function send(ExternalConnectorTargetDefinition $target, ExternalMailMessage $message): void
    {
        if ($target->configurationReference !== self::CONFIGURATION_REFERENCE) {
            throw new \LogicException('The Symfony mailer target must use the mailer.default configuration reference.');
        }

        $email = (new Email())
            ->from($this->fromAddress)
            ->to(...$message->recipients)
            ->subject($message->subject)
            ->text($message->text);

        if ($message->html !== null) {
            $email->html($message->html);
        }

        $this->mailer->send($email);
    }
}

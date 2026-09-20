<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Webauthn\Bundle\Security\Http\Authenticator\WebauthnAuthenticator;

final class SecurityAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuditLogger $audit, private readonly EntityManagerInterface $entityManager) {}

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onSuccess', LoginFailureEvent::class => 'onFailure'];
    }

    public function onSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        $passkeyLogin = $event->getAuthenticator() instanceof WebauthnAuthenticator;
        $event->getRequest()->getSession()->set('two_factor_verified', $passkeyLogin || !$user instanceof User || !$user->isTwoFactorEnabled());
        $this->audit->record('security.login.success', 'authentication', null, 'Erfolgreiche Anmeldung');
        $this->entityManager->flush();
    }

    public function onFailure(LoginFailureEvent $event): void
    {
        $this->audit->record('security.login.failure', 'authentication', null, 'Fehlgeschlagene Anmeldung');
        $this->entityManager->flush();
    }
}

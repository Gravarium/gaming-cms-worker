<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class UserSessionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UserSessionRepository $sessions,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLogin', KernelEvents::REQUEST => ['onRequest', -20]];
    }

    public function onLogin(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        $request = $event->getRequest();
        if (!$user instanceof User || !$request->hasSession()) { return; }

        $user->markLogin();
        $session = $this->sessions->findBySessionId($request->getSession()->getId());
        if ($session === null) {
            $this->entityManager->persist(new UserSession($user, $request->getSession()->getId(), $request->getClientIp(), $request->headers->get('User-Agent')));
        }
        $this->entityManager->flush();
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $request = $event->getRequest();
        $user = $this->security->getUser();
        if (!$user instanceof User || !$request->hasSession()) { return; }

        $session = $this->sessions->findBySessionId($request->getSession()->getId());
        $created = false;
        if ($session === null) {
            $session = new UserSession($user, $request->getSession()->getId(), $request->getClientIp(), $request->headers->get('User-Agent'));
            $this->entityManager->persist($session);
            $created = true;
        }

        if ($session->getUser() !== $user || $session->isRevoked() || $session->getSecurityVersion() !== $user->getSecurityVersion() || !$user->isActive() || $user->isLocked()) {
            $request->getSession()->invalidate();
            $event->setResponse(new RedirectResponse($this->urls->generate('app_login')));
            return;
        }

        if ($session->getLastSeenAt() < new \DateTimeImmutable('-5 minutes')) {
            $session->touch($request->getClientIp(), $request->headers->get('User-Agent'));
            $user->markSeen();
            $created = true;
        }

        if ($created) { $this->entityManager->flush(); }
    }
}

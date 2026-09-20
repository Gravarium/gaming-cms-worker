<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class TwoFactorGateSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Security $security, private readonly UrlGeneratorInterface $urls) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['requireSecondFactor', -10]];
    }

    public function requireSecondFactor(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isTwoFactorEnabled()) { return; }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');
        if (in_array($route, ['app_two_factor_challenge', 'app_logout'], true)) { return; }
        if ($request->getSession()->get('two_factor_verified') === true) { return; }

        $event->setResponse(new RedirectResponse($this->urls->generate('app_two_factor_challenge')));
    }
}

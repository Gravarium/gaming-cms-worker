<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Internationalization\LocalePolicy;
use App\Repository\SiteSettingsRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 18)]
final readonly class LocaleSubscriber
{
    public function __construct(
        private SiteSettingsRepository $settings,
        private LocalePolicy $policy,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $settings = $this->settings->current();
        $candidate = (string) $request->cookies->get(LocalePolicy::COOKIE, '');

        $request->setLocale($this->policy->choose(
            $candidate,
            $settings->getEnabledLocales(),
            $settings->getDefaultLocale(),
        ));
    }
}

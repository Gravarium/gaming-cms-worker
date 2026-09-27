<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Widget\UpcomingGuildEventsWidgetProvider;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE, priority: -128)]
final readonly class GuildEventWidgetCacheSubscriber
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()
            || $event->getRequest()->attributes->get(UpcomingGuildEventsWidgetProvider::PERSONALIZED_CACHE_ATTRIBUTE) !== true
        ) {
            return;
        }

        $event->getResponse()->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $event->getResponse()->headers->set('Pragma', 'no-cache');
    }
}

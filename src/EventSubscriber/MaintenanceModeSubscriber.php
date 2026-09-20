<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class MaintenanceModeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/.maintenance')]
        private string $markerFile,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 1000]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !is_file($this->markerFile)) {
            return;
        }

        $response = new Response(
            '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Wartung</title><body><main><h1>Kurze Wartung</h1><p>Das Gaming CMS wird gerade sicher aktualisiert. Bitte versuche es in wenigen Minuten erneut.</p></main></body></html>',
            Response::HTTP_SERVICE_UNAVAILABLE,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Retry-After' => '120', 'Cache-Control' => 'no-store'],
        );
        $event->setResponse($response);
    }
}

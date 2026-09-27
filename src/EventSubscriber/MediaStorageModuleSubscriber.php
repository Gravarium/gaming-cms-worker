<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Module\CmsModuleManager;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class MediaStorageModuleSubscriber
{
    private const ROUTE_PREFIX = 'app_admin_media';

    public function __construct(private CmsModuleManager $modules) {}

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 25)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }

        $route = (string) $event->getRequest()->attributes->get('_route');
        if ($route !== self::ROUTE_PREFIX && !str_starts_with($route, self::ROUTE_PREFIX.'_')) {
            return;
        }

        if (!$this->modules->isEnabled('media')) {
            throw new NotFoundHttpException('Dieses CMS-Modul ist deaktiviert.');
        }
    }
}

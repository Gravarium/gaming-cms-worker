<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Module\CmsModuleManager;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class DisabledModuleSubscriber
{
    public function __construct(private CmsModuleManager $modules) {}

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $route = (string) $event->getRequest()->attributes->get('_route');
        if ($route === '' || str_starts_with($route, 'app_admin_module_')) { return; }
        $module = $this->modules->moduleForRoute($route);
        if ($module !== null && !$this->modules->isEnabled($module)) {
            throw new NotFoundHttpException('Dieses CMS-Modul ist deaktiviert.');
        }
    }
}

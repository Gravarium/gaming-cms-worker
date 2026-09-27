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
    /** @var list<string> */
    private const GAMING_ROUTE_FALLBACKS = [
        'app_admin_game_new',
        'app_admin_game_edit',
        'app_admin_game_delete',
    ];

    public function __construct(private CmsModuleManager $modules) {}

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $route = (string) $event->getRequest()->attributes->get('_route');
        if ($route === '' || str_starts_with($route, 'app_admin_module_')) { return; }
        $module = $this->modules->moduleForRoute($route) ?? $this->fallbackModuleForRoute($route);
        if ($module !== null && !$this->modules->isEnabled($module)) {
            throw new NotFoundHttpException('Dieses CMS-Modul ist deaktiviert.');
        }
    }

    private function fallbackModuleForRoute(string $route): ?string
    {
        return in_array($route, self::GAMING_ROUTE_FALLBACKS, true) ? 'gaming' : null;
    }
}

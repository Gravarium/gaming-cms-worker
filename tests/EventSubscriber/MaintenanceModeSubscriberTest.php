<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\MaintenanceModeSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class MaintenanceModeSubscriberTest extends TestCase
{
    public function testReturnsSanitized503WhileMarkerExists(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'maintenance-');
        self::assertIsString($marker);

        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, Request::create('/admin'), HttpKernelInterface::MAIN_REQUEST);
        (new MaintenanceModeSubscriber($marker))->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(503, $event->getResponse()?->getStatusCode());
        self::assertSame('120', $event->getResponse()?->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $event->getResponse()?->headers->get('Cache-Control'));
        self::assertStringNotContainsString($marker, (string) $event->getResponse()?->getContent());

        unlink($marker);
    }

    public function testDoesNothingWithoutMarker(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST);
        (new MaintenanceModeSubscriber('/definitely/missing'))->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }
}

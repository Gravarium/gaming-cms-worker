<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class PublicGlobalSearchWidgetProviderTest extends KernelTestCase
{
    public function testWidgetRendersTheExistingGlobalSearchRoute(): void
    {
        self::bootKernel();

        $registry = self::getContainer()->get(WidgetRegistry::class);
        $definition = $registry->get('search.global');

        self::assertInstanceOf(WidgetDefinition::class, $definition);
        self::assertSame('core', $definition->module);
        self::assertFalse($definition->multiple);
        self::assertTrue($registry->available('search.global'));
        self::assertSame([], $registry->data('search.global', []));

        $html = self::getContainer()->get(Environment::class)->render($definition->template);

        self::assertStringContainsString('action="/search/global"', $html);
        self::assertStringContainsString('method="get"', $html);
        self::assertStringContainsString('role="search"', $html);
        self::assertStringContainsString('for="global-search-widget-query"', $html);
        self::assertStringContainsString('type="search"', $html);
        self::assertStringContainsString('name="q"', $html);
        self::assertStringContainsString('minlength="2"', $html);
        self::assertStringContainsString('maxlength="100"', $html);
        self::assertStringContainsString('type="submit"', $html);
    }
}

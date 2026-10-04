<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Widget\GamePublisherDirectoryWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class GamePublisherDirectoryWidgetProviderTest extends KernelTestCase
{
    public function testPublisherDirectoryWidgetIsRegisteredAndLinksToThePublicDirectory(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $registry = $container->get(WidgetRegistry::class);

        $definition = $registry->get(GamePublisherDirectoryWidgetProvider::KEY);
        self::assertInstanceOf(WidgetDefinition::class, $definition);
        self::assertSame('gaming', $definition->module);
        self::assertSame('widget/game_publisher_directory.html.twig', $definition->template);

        $markup = $container->get(Environment::class)->render($definition->template, [
            'widget' => ['id' => 'publisher-directory-fixture'],
            'config' => [],
            'data' => [],
        ]);
        self::assertStringContainsString('href="/games/publishers"', $markup);
    }
}

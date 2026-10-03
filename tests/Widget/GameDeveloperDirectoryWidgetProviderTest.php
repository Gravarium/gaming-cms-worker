<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Widget\GameDeveloperDirectoryWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class GameDeveloperDirectoryWidgetProviderTest extends KernelTestCase
{
    public function testDeveloperDirectoryWidgetIsRegisteredAndLinksToThePublicDirectory(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $definition = $container->get(WidgetRegistry::class)->get(GameDeveloperDirectoryWidgetProvider::KEY);

        self::assertInstanceOf(WidgetDefinition::class, $definition);
        self::assertSame('gaming', $definition->module);
        self::assertSame('widget/game_developer_directory.html.twig', $definition->template);

        $markup = $container->get(Environment::class)->render($definition->template, [
            'widget' => ['id' => 'developer-directory-fixture'],
            'config' => [],
            'data' => [],
        ]);
        self::assertStringContainsString('href="/games/developers"', $markup);
    }
}

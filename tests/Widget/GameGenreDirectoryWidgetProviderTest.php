<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Widget\GameGenreDirectoryWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class GameGenreDirectoryWidgetProviderTest extends WebTestCase
{
    public function testWidgetIsRegisteredForGamingAndLinksToDirectory(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        $container = $client->getContainer();
        $registry = $container->get(WidgetRegistry::class);
        $definition = $registry->get(GameGenreDirectoryWidgetProvider::KEY);

        self::assertInstanceOf(WidgetDefinition::class, $definition);
        self::assertSame('gaming', $definition->module);
        self::assertSame('widget/game_genre_directory.html.twig', $definition->template);
        self::assertSame([], $registry->data(GameGenreDirectoryWidgetProvider::KEY, []));

        $markup = $container->get(Environment::class)->render($definition->template, [
            'widget' => ['id' => 'genres-fixture'],
            'config' => [],
            'data' => [],
        ]);
        self::assertStringContainsString('href="/game-genres"', $markup);
        self::assertStringContainsString('Spiele nach Genre entdecken', $markup);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Widget\GameComparisonWidgetProvider;
use PHPUnit\Framework\TestCase;

final class GameComparisonWidgetProviderTest extends TestCase
{
    public function testProvidesGamingComparisonEntryPoint(): void
    {
        $provider = new GameComparisonWidgetProvider();
        $definition = $provider->definitions()[0];
        self::assertSame(GameComparisonWidgetProvider::KEY, $definition->key);
        self::assertSame('gaming', $definition->module);
        self::assertSame('widget/game_comparison.html.twig', $definition->template);
        self::assertSame([], $provider->data($definition->key, []));
    }
}

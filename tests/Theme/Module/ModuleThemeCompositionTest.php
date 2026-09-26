<?php

declare(strict_types=1);

namespace App\Tests\Theme\Module;

use App\Theme\ThemeRegistry;
use App\Theme\Module\ModuleThemeCompositionRegistry;
use PHPUnit\Framework\TestCase;

final class ModuleThemeCompositionTest extends TestCase
{
    public function testThemesUseDifferentStructuresAndWidgetOrder(): void
    {
        $registry = new ModuleThemeCompositionRegistry(new ThemeRegistry());

        $nebula = $registry->get('nebula');
        $ember = $registry->get('ember');
        $ocean = $registry->get('ocean');

        self::assertSame('editorial', $nebula->structure);
        self::assertSame('split', $ember->structure);
        self::assertSame('catalogue', $ocean->structure);
        self::assertNotSame($nebula->contentWidgets, $ember->contentWidgets);
        self::assertNotSame($ember->contentWidgets, $ocean->contentWidgets);
        self::assertNotSame($nebula->template, $ocean->template);
    }

    public function testUnknownThemeFallsBackToSafeComposition(): void
    {
        $composition = (new ModuleThemeCompositionRegistry(new ThemeRegistry()))->get('unknown-theme');
        self::assertSame('themes/modules/nebula.html.twig', $composition->template);
        self::assertSame('editorial', $composition->structure);
    }
}

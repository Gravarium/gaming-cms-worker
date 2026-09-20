<?php

declare(strict_types=1);

namespace App\Tests\Theme;

use App\Theme\ThemeDefinition;
use App\Theme\ThemeRegistry;
use PHPUnit\Framework\TestCase;

final class ThemeRegistryTest extends TestCase
{
    public function testBundledThemesExposeStableMetadata(): void
    {
        $registry = new ThemeRegistry();

        self::assertSame(['Nebula 1.0.0' => 'nebula', 'Ember 1.0.0' => 'ember', 'Ocean 1.0.0' => 'ocean'], $registry->choices());
        self::assertSame('ember', $registry->get('ember')->key);
        self::assertSame('nebula', $registry->get('unknown')->key);
    }

    public function testThemeKeysCannotEscapeTheContract(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ThemeDefinition('../private', 'Invalid', '1.0.0', '^1.0', []);
    }
}

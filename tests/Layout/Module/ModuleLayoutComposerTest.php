<?php

declare(strict_types=1);

namespace App\Tests\Layout\Module;

use App\Layout\Module\ModuleLayoutComposer;
use App\Theme\Module\ModuleThemeCompositionRegistry;
use App\Theme\ThemeRegistry;
use App\Widget\Module\ModuleWidgetCatalog;
use App\Widget\Module\ModuleWidgetPayload;
use App\Widget\Module\ModuleWidgetViewer;
use PHPUnit\Framework\TestCase;

final class ModuleLayoutComposerTest extends TestCase
{
    public function testCompositionKeepsContentAndSidebarSeparate(): void
    {
        $composition = (new ModuleThemeCompositionRegistry(new ThemeRegistry()))->get('ocean');
        $catalog = new ModuleWidgetCatalog();
        $rendered = [];

        foreach (['gaming.releases', 'content.guides', 'gaming.server-status', 'gaming.forum'] as $key) {
            $definition = $catalog->get($key);
            self::assertNotNull($definition);
            $rendered[$key] = [
                'definition' => $definition,
                'payload' => ModuleWidgetPayload::noItems(),
                'config' => [],
            ];
        }

        $view = (new ModuleLayoutComposer())->compose($composition, $rendered);

        self::assertSame(['gaming.releases', 'content.guides'], array_map(
            static fn (array $widget): string => $widget['definition']->key,
            $view['regions']['content'],
        ));
        self::assertSame(['gaming.server-status', 'gaming.forum'], array_map(
            static fn (array $widget): string => $widget['definition']->key,
            $view['regions']['sidebar'],
        ));
        self::assertSame($composition->template, $view['template']);
    }

    public function testViewerFactoryIsAnonymousByDefault(): void
    {
        self::assertFalse(ModuleWidgetViewer::anonymous()->authenticated);
    }
}

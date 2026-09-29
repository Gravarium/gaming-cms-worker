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

        foreach (['gaming.releases', 'gaming.guides', 'downloads.catalogue', 'gaming.server-status', 'gaming.forum', 'gaming.presence'] as $key) {
            $definition = $catalog->get($key);
            self::assertNotNull($definition);
            $rendered[$key] = [
                'definition' => $definition,
                'payload' => ModuleWidgetPayload::noItems(),
                'config' => [],
            ];
        }

        $view = (new ModuleLayoutComposer())->compose($composition, $rendered);

        self::assertSame(['gaming.releases', 'gaming.guides', 'downloads.catalogue'], array_map(
            static fn (array $widget): string => $widget['definition']->key,
            $view['regions']['content'],
        ));
        self::assertSame(['gaming.server-status', 'gaming.forum', 'gaming.presence'], array_map(
            static fn (array $widget): string => $widget['definition']->key,
            $view['regions']['sidebar'],
        ));
        self::assertSame($composition->template, $view['template']);
    }

    public function testEveryBundledCompositionReferencesRegisteredWidgets(): void
    {
        $themes = new ThemeRegistry();
        $compositions = new ModuleThemeCompositionRegistry($themes);
        $catalog = new ModuleWidgetCatalog();

        foreach (['nebula', 'ember', 'ocean', 'gravarium-fantasy', 'gravarium-cinematic'] as $theme) {
            $composition = $compositions->get($theme);
            foreach (array_merge($composition->contentWidgets, $composition->sidebarWidgets) as $key) {
                self::assertNotNull($catalog->get($key), $theme.' references unknown widget '.$key);
            }
        }
    }

    public function testViewerFactoryIsAnonymousByDefault(): void
    {
        self::assertFalse(ModuleWidgetViewer::anonymous()->authenticated);
    }
}

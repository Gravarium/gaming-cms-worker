<?php

declare(strict_types=1);

namespace App\Tests\Widget\Module;

use App\Widget\Module\ModuleWidgetAccessPolicy;
use App\Widget\Module\ModuleWidgetDefinition;
use App\Widget\Module\ModuleWidgetPayload;
use App\Widget\Module\ModuleWidgetViewer;
use PHPUnit\Framework\TestCase;

final class ModuleWidgetPolicyTest extends TestCase
{
    public function testPublicAndAuthenticatedBoundaries(): void
    {
        $public = new ModuleWidgetDefinition('gaming.guides', 'Guides', 'gaming', 'guides', 'widgets/modules/cards.html.twig');
        $private = new ModuleWidgetDefinition('gaming.events', 'Events', 'gaming', 'events', 'widgets/modules/cards.html.twig', 'authenticated');
        $policy = new ModuleWidgetAccessPolicy();

        self::assertTrue($policy->allows($public, ModuleWidgetViewer::anonymous()));
        self::assertFalse($policy->allows($private, ModuleWidgetViewer::anonymous()));
        self::assertTrue($policy->allows($private, new ModuleWidgetViewer(true)));
    }

    public function testGuildVisibilityRequiresTheSameGuild(): void
    {
        $definition = new ModuleWidgetDefinition('gaming.guild-events', 'Events', 'gaming', 'events', 'widgets/modules/cards.html.twig', 'guild');
        $viewer = new ModuleWidgetViewer(true, false, 7, 4);
        $policy = new ModuleWidgetAccessPolicy();

        self::assertTrue($policy->allows($definition, $viewer, 4));
        self::assertFalse($policy->allows($definition, $viewer, 9));
        self::assertFalse($policy->allows($definition, ModuleWidgetViewer::anonymous(), 4));
    }

    public function testPayloadRejectsObjects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ModuleWidgetPayload(ModuleWidgetPayload::STATUS_READY, [['value' => new \stdClass()]]);
    }

    public function testPublicRankingsWidgetCanRenderForAnonymousViewers(): void
    {
        $definition = (new \App\Widget\Module\ModuleWidgetCatalog())->get('gaming.rankings');
        self::assertNotNull($definition);
        self::assertTrue((new ModuleWidgetAccessPolicy())->allows($definition, ModuleWidgetViewer::anonymous()));
    }

    public function testPayloadRejectsExternalLinks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleWidgetPayload::ready([['href' => 'javascript:alert(1)']]);
    }

    public function testPayloadBoundsEveryStringAndItemShape(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleWidgetPayload::ready([['title' => str_repeat('x', ModuleWidgetPayload::MAX_STRING_BYTES + 1)]]);
    }

    public function testPayloadRejectsOversizedCollections(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleWidgetPayload::ready(array_fill(0, ModuleWidgetPayload::MAX_ITEMS + 1, ['title' => 'entry']));
    }

    public function testViewerRejectsNonPositiveGuildIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ModuleWidgetViewer(true, guildIds: [0]);
    }

    public function testPayloadRejectsAssociativeItemCollections(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ModuleWidgetPayload(ModuleWidgetPayload::STATUS_READY, ['items' => [['title' => 'item']]]);
    }

    public function testPayloadRejectsNonArrayItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ModuleWidgetPayload(ModuleWidgetPayload::STATUS_READY, ['invalid']);
    }

    public function testPayloadRejectsNonStringItemKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ModuleWidgetPayload(ModuleWidgetPayload::STATUS_READY, [[7 => 'invalid']]);
    }

    public function testViewerRejectsAssociativeGuildIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ModuleWidgetViewer(true, guildIds: [1 => 7]);
    }

    public function testViewerRejectsNonIntegerGuildIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ModuleWidgetViewer(true, guildIds: ['7']);
    }

}

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
        $public = new ModuleWidgetDefinition('content.guides', 'Guides', 'content', 'guides', 'widgets/modules/cards.html.twig');
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

    public function testPayloadNeverAcceptsObjectsOrUnknownStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ModuleWidgetPayload(ModuleWidgetPayload::STATUS_READY, [['value' => new \stdClass()]]);
    }
}

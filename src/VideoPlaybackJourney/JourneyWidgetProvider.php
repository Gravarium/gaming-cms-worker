<?php
declare(strict_types=1);
namespace App\VideoPlaybackJourney;
use App\Video\Discovery\VideoModuleAvailability;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetProvider;
final readonly class JourneyWidgetProvider implements WidgetProvider
{
    public function __construct(private VideoModuleAvailability $module) {}
    public function definitions(): array { return [new WidgetDefinition('video.viewing-journey', 'Video · Playlists & Weiterschauen', 'video', 'widget/video_viewing_journey.html.twig')]; }
    public function data(string $key, array $config): array { return ['available' => $key === 'video.viewing-journey' && $this->module->enabled()]; }
}

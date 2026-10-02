<?php

declare(strict_types=1);

namespace App\VideoPlayerChoice;

use App\Video\Discovery\VideoModuleAvailability;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetProvider;

final readonly class PlayerChoiceWidgetProvider implements WidgetProvider
{
    public function __construct(private VideoModuleAvailability $module) {}
    public function definitions(): array
    {
        return [new WidgetDefinition('video.player-choice', 'Video · Player auswählen', 'video', 'widget/video_player_choice.html.twig')];
    }
    public function data(string $key, array $config): array
    {
        return ['available' => $key === 'video.player-choice' && $this->module->enabled()];
    }
}

<?php

declare(strict_types=1);

namespace App\Widget;

use App\CommunityInteraction\ContentInteractionQuery;

final readonly class ContentDiscussionWidgetProvider implements WidgetProvider
{
    public function __construct(private ContentInteractionQuery $interactions)
    {
    }

    public function definitions(): array
    {
        return [
            new WidgetDefinition(
                'content.discussions',
                'Aktuelle Diskussionen',
                'content',
                'widget/content_discussions.html.twig',
                [],
                true,
            ),
        ];
    }

    public function data(string $key, array $config): array
    {
        if ($key !== 'content.discussions') {
            return [];
        }

        $count = $config['count'] ?? 6;

        return ['items' => $this->interactions->recentPublicDiscussions(is_int($count) ? $count : 6)];
    }
}

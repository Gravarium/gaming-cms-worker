<?php

declare(strict_types=1);

namespace App\Widget;

use App\Entity\GuildEvent;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class PublicGuildEventsWidgetProvider implements WidgetProvider
{
    private const KEY = 'gaming.public-guild-events';
    private const CACHE_KEY = '_cms_widget_data_gaming.public-guild-events';

    public function __construct(
        private PublicGuildEventsQuery $events,
        private RequestStack $requests,
    ) {
    }

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [new WidgetDefinition(
            self::KEY,
            'Kommende öffentliche Gildentermine',
            'gaming',
            'widget/public_guild_events.html.twig',
            [],
            true,
            ['count' => ['label' => 'Anzahl der Termine', 'type' => 'int', 'default' => 6, 'min' => 1, 'max' => PublicGuildEventsQuery::MAX_RESULTS]],
        )];
    }

    /** @param array<string, string|int|bool> $config
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        if ($key !== self::KEY) {
            return [];
        }

        $request = $this->requests->getCurrentRequest();
        /** @var list<GuildEvent>|null $events */
        $events = $request?->attributes->get(self::CACHE_KEY);
        if (!is_array($events)) {
            $events = $this->events->upcoming(new \DateTimeImmutable(), PublicGuildEventsQuery::MAX_RESULTS);
            $request?->attributes->set(self::CACHE_KEY, $events);
        }

        $count = $config['count'] ?? 6;
        $count = is_int($count) ? max(1, min(PublicGuildEventsQuery::MAX_RESULTS, $count)) : 6;

        return ['events' => array_slice($events, 0, $count)];
    }
}

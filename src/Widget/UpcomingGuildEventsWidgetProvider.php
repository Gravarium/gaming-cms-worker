<?php

declare(strict_types=1);

namespace App\Widget;

use App\Entity\GuildEvent;
use App\Entity\User;
use App\Widget\GuildEvent\UpcomingGuildEventsQuery;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class UpcomingGuildEventsWidgetProvider implements WidgetProvider
{
    public const KEY = 'gaming.upcoming-guild-events';
    public const PERSONALIZED_CACHE_ATTRIBUTE = '_cms_personalized_upcoming_guild_events';
    private const REQUEST_CACHE_PREFIX = '_cms_upcoming_guild_events_user_';
    private const DEFAULT_COUNT = 6;

    public function __construct(
        private UpcomingGuildEventsQuery $events,
        private Security $security,
        private RequestStack $requests,
    ) {
    }

    /** @return list<WidgetDefinition> */
    public function definitions(): array
    {
        return [new WidgetDefinition(
            self::KEY,
            'Nächste Gilden-Termine',
            'gaming',
            'widget/upcoming_guild_events.html.twig',
        )];
    }

    /**
     * @param array<string, string|int|bool> $config
     * @return array<string, mixed>
     */
    public function data(string $key, array $config): array
    {
        if ($key !== self::KEY) {
            return [];
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return ['authenticated' => false, 'events' => []];
        }

        // Keep personalized page data out of shared caches, including when the
        // widget itself is rendered from a subrequest.
        $request = $this->requests->getMainRequest();
        if (!$request instanceof Request) {
            return ['authenticated' => false, 'events' => []];
        }
        $request->attributes->set(self::PERSONALIZED_CACHE_ATTRIBUTE, true);

        $requestedCount = $config['count'] ?? self::DEFAULT_COUNT;
        $count = is_int($requestedCount)
            ? max(1, min(UpcomingGuildEventsQuery::MAX_RESULTS, $requestedCount))
            : self::DEFAULT_COUNT;

        $userKey = (string) ($user->getId() ?? spl_object_id($user));
        $cacheKey = self::REQUEST_CACHE_PREFIX.$userKey;

        /** @var list<GuildEvent>|null $cachedEvents */
        $cachedEvents = $request->attributes->get($cacheKey);
        if ($cachedEvents === null) {
            $cachedEvents = $this->events->findForUser($user, UpcomingGuildEventsQuery::MAX_RESULTS);
            $request->attributes->set($cacheKey, $cachedEvents);
        }

        return [
            'authenticated' => true,
            'events' => array_slice($cachedEvents, 0, $count),
        ];
    }
}

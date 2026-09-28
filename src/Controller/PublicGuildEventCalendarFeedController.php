<?php

declare(strict_types=1);

namespace App\Controller;

use App\GuildEventCalendarFeed\PublicGuildEventCalendarBuilder;
use App\Module\CmsModuleManager;
use App\Widget\PublicGuildEventsQuery;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicGuildEventCalendarFeedController extends AbstractController
{
    public function __construct(
        private readonly PublicGuildEventsQuery $events,
        private readonly PublicGuildEventCalendarBuilder $calendar,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/gaming/events/calendar.ics', name: 'app_public_guild_event_calendar_feed', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        $events = $this->events->upcoming(
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            PublicGuildEventsQuery::MAX_RESULTS,
        );

        return new Response($this->calendar->build($events), Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

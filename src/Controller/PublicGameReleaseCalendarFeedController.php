<?php

declare(strict_types=1);

namespace App\Controller;

use App\GameReleaseFeed\IcalendarBuilder;
use App\Module\CmsModuleManager;
use App\Repository\GameReleaseCalendarFeedRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicGameReleaseCalendarFeedController extends AbstractController
{
    private const WINDOW_MONTHS = 18;

    public function __construct(
        private readonly GameReleaseCalendarFeedRepository $releases,
        private readonly IcalendarBuilder $calendar,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/games/releases/calendar.ics', name: 'app_game_catalogue_release_calendar_feed', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $until = $now->modify('+'.self::WINDOW_MONTHS.' months');
        $body = $this->calendar->build($this->releases->upcoming($now, $until), $now);

        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="game-releases.ics"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

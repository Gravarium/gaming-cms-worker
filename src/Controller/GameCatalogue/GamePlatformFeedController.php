<?php

declare(strict_types=1);

namespace App\Controller\GameCatalogue;

use App\GamePlatformDirectory\PublicGamePlatformQuery;
use App\GameReleaseFeed\IcalendarBuilder;
use App\GameReleaseFeed\RssFeedBuilder;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/games/platforms/{slug}', requirements: ['slug' => '[a-z0-9-]{1,140}'])]
final class GamePlatformFeedController extends AbstractController
{
    public function __construct(
        private readonly PublicGamePlatformQuery $platforms,
        private readonly RssFeedBuilder $rss,
        private readonly IcalendarBuilder $calendar,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/feed.xml', name: 'app_game_platform_feed_rss', methods: ['GET'])]
    public function rss(string $slug): Response
    {
        $result = $this->result($slug);
        $body = $this->rss->build($result['releases'], new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        return new Response($body, Response::HTTP_OK, $this->headers('application/rss+xml; charset=UTF-8', 'platform-releases.xml'));
    }

    #[Route('/calendar.ics', name: 'app_game_platform_feed_ical', methods: ['GET'])]
    public function calendar(string $slug): Response
    {
        $result = $this->result($slug);
        $body = $this->calendar->build($result['releases'], new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        return new Response($body, Response::HTTP_OK, $this->headers('text/calendar; charset=UTF-8', 'platform-releases.ics'));
    }

    /** @return array{platform: \App\Entity\GameCatalogue\GamePlatform, releases: list<\App\Entity\GameCatalogue\GameRelease>} */
    private function result(string $slug): array
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
        $result = $this->platforms->feed($slug);
        if ($result === null) {
            throw $this->createNotFoundException();
        }

        return $result;
    }

    /** @return array<string, string> */
    private function headers(string $type, string $filename): array
    {
        return [
            'Content-Type' => $type,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}

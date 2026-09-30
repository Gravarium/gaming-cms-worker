<?php

declare(strict_types=1);

namespace App\Controller;

use App\GameReleaseFeed\RssFeedBuilder;
use App\Module\CmsModuleManager;
use App\Repository\GameReleaseCalendarFeedRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicGameReleaseRssFeedController extends AbstractController
{
    private const WINDOW_MONTHS = 18;

    public function __construct(
        private readonly GameReleaseCalendarFeedRepository $releases,
        private readonly RssFeedBuilder $feed,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/games/releases/feed.xml', name: 'app_game_catalogue_release_rss', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $until = $now->modify('+'.self::WINDOW_MONTHS.' months');
        $body = $this->feed->build($this->releases->upcoming($now, $until), $now);

        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="game-releases.xml"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

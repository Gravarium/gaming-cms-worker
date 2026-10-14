<?php

declare(strict_types=1);

namespace App\Controller;

use App\GameReleaseFeed\JsonFeedBuilder;
use App\Module\CmsModuleManager;
use App\Repository\GameReleaseCalendarFeedRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicGameReleaseJsonFeedController extends AbstractController
{
    private const WINDOW_MONTHS = 18;

    public function __construct(
        private readonly GameReleaseCalendarFeedRepository $releases,
        private readonly JsonFeedBuilder $feed,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/games/releases/feed.json', name: 'app_game_catalogue_release_json', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $until = $now->modify('+'.self::WINDOW_MONTHS.' months');
        $body = $this->feed->build($this->releases->upcoming($now, $until));

        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => 'application/feed+json; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="game-releases.json"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

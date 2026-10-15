<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentReleaseFeed\PublicContentReleaseJsonFeedBuilder;
use App\Module\CmsModuleManager;
use App\Repository\ContentReleaseRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicContentReleaseJsonFeedController
{
    private const FEED_SIZE = 50;

    public function __construct(
        private readonly ContentReleaseRepository $releases,
        private readonly PublicContentReleaseJsonFeedBuilder $feed,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/releases.jsonfeed', name: 'app_public_content_release_json_feed', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->modules->isEnabled('content')) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $json = $this->feed->build($this->releases->findPublicPublished(self::FEED_SIZE, 0, $now), $now);

        return new Response($json, Response::HTTP_OK, [
            'Cache-Control' => 'private, no-store',
            'Content-Type' => 'application/feed+json; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

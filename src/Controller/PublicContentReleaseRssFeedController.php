<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentReleaseFeed\PublicContentReleaseRssFeedBuilder;
use App\Module\CmsModuleManager;
use App\Repository\ContentReleaseRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicContentReleaseRssFeedController
{
    private const ITEM_LIMIT = 50;

    public function __construct(
        private readonly ContentReleaseRepository $releases,
        private readonly PublicContentReleaseRssFeedBuilder $feed,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/releases.rss', name: 'app_public_content_release_rss', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->modules->isEnabled('content')) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $body = $this->feed->build($this->releases->findPublicPublished(self::ITEM_LIMIT, 0, $now), $now);

        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

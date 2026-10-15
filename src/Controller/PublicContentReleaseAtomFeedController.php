<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentReleaseFeed\PublicContentReleaseAtomFeedBuilder;
use App\Module\CmsModuleManager;
use App\Repository\ContentReleaseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicContentReleaseAtomFeedController extends AbstractController
{
    private const FEED_SIZE = 50;

    public function __construct(
        private readonly ContentReleaseRepository $releases,
        private readonly PublicContentReleaseAtomFeedBuilder $feed,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/releases.atom', name: 'app_public_content_release_atom', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->modules->isEnabled('content')) {
            throw $this->createNotFoundException();
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $feed = $this->feed->build($this->releases->findPublicPublished(self::FEED_SIZE, 0, $now), $now);
        $body = $this->renderView('content_release/public_atom_feed.xml.twig', [
            'feed' => $feed,
        ]);
        $response = new Response($body, Response::HTTP_OK, [
            'Cache-Control' => 'private, no-store',
            'Content-Type' => 'application/atom+xml; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $lastModified = $feed['last_modified'];
        if ($lastModified instanceof \DateTimeImmutable) {
            $response->setLastModified($lastModified);
        }

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\GameReleaseFeed\PublicGameReleaseFeedQuery;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PublicGameReleaseFeedController extends AbstractController
{
    public function __construct(
        private readonly PublicGameReleaseFeedQuery $releases,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/games/releases.rss', name: 'app_gaming_game_release_feed', methods: ['GET'])]
    public function rss(Request $request): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw new NotFoundHttpException();
        }

        $from = new \DateTimeImmutable('today');
        $to = $from->modify('+18 months');
        $response = $this->render('game_release_feed.xml.twig', [
            'releases' => $this->releases->findUpcoming($from, $to),
        ]);
        $response->headers->set('Content-Type', 'application/rss+xml; charset=UTF-8');

        // Absolute links are part of the representation, so the ETag varies by request host.
        $response->setEtag(hash('sha256', (string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge(0);
        $response->setSharedMaxAge(0);
        $response->isNotModified($request);

        return $response;
    }
}

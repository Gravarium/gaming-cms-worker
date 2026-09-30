<?php

declare(strict_types=1);

namespace App\Controller;

use App\GameGuide\PublicGameGuideQuery;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicGameGuideFeedController extends AbstractController
{
    public function __construct(
        private readonly PublicGameGuideQuery $guides,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/gaming/guides.xml', name: 'app_gaming_guide_feed', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('public/feed.xml.twig', [
            'guides' => $this->guides->latest(PublicGameGuideQuery::MAX_RESULTS),
        ]);
        $response->headers->set('Content-Type', 'application/rss+xml; charset=UTF-8');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}

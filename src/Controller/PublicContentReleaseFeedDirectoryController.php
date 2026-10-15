<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicContentReleaseFeedDirectoryController extends AbstractController
{
    public function __construct(private readonly CmsModuleManager $modules)
    {
    }

    #[Route('/releases/feeds', name: 'app_public_content_release_feeds', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->modules->isEnabled('content')) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('content_release/feed_directory.html.twig');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}

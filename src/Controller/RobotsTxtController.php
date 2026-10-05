<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class RobotsTxtController extends AbstractController
{
    #[Route('/robots.txt', name: 'app_robots_txt', methods: ['GET'], stateless: true)]
    public function index(): Response
    {
        $sitemapUrl = $this->generateUrl('app_content_sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $response = new Response(
            sprintf("User-agent: *\nDisallow: /admin\nSitemap: %s\n", $sitemapUrl),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
        $response->setPublic();
        $response->setMaxAge(300);
        $response->setSharedMaxAge(300);

        return $response;
    }
}

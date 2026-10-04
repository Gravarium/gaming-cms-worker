<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Video;
use App\Module\CmsModuleManager;
use App\VideoSitemap\PublicVideoSitemapQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicVideoSitemapController extends AbstractController
{
    private const CACHE_SECONDS = 300;

    public function __construct(
        private readonly PublicVideoSitemapQuery $videos,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/sitemap-videos.xml', name: 'app_video_sitemap', methods: ['GET'])]
    public function videos(Request $request): Response
    {
        $videos = $this->videos->findPublishedVideos();
        $response = $this->render('video_sitemap.xml.twig', ['videos' => $videos]);
        $signature = implode('|', array_map(
            static fn (Video $video): string => ($video->getId() ?? 0).':'.$video->getSlug().':'.($video->getPublishedAt()?->format('U.u') ?? ''),
            $videos,
        ));

        return $this->cacheXml($request, $response, $signature);
    }

    #[Route('/sitemap-index.xml', name: 'app_public_sitemap_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $locations = [];
        if ($this->modules->isEnabled('content')) {
            $locations[] = $this->generateUrl('app_content_sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL);
        }
        if ($this->modules->isEnabled('video')) {
            $locations[] = $this->generateUrl('app_video_sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        $response = $this->render('sitemap_index.xml.twig', ['locations' => $locations]);

        return $this->cacheXml($request, $response, implode('|', $locations));
    }

    private function cacheXml(Request $request, Response $response, string $signature): Response
    {
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(self::CACHE_SECONDS);
        $response->setSharedMaxAge(self::CACHE_SECONDS);
        $response->setEtag(hash('sha256', $request->getSchemeAndHttpHost().'|'.$signature));
        $response->isNotModified($request);

        return $response;
    }
}

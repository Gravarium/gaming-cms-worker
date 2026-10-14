<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicNewsOpenSearchController extends AbstractController
{
    private const CACHE_SECONDS = 3600;

    #[Route('/news/opensearch.xml', name: 'app_news_opensearch', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $searchUrl = $this->generateUrl('app_content_search', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $response = $this->render('content/news_opensearch.xml.twig', [
            'searchUrl' => $searchUrl,
        ]);
        $response->headers->set('Content-Type', 'application/opensearchdescription+xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(self::CACHE_SECONDS);
        $response->setSharedMaxAge(self::CACHE_SECONDS);
        $response->setEtag(hash('sha256', $searchUrl));
        $response->isNotModified($request);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

final class PublicNewsSearchPageController extends AbstractController
{
    private const MAX_QUERY_LENGTH = 80;
    private const MAX_RESULTS = 20;

    #[Route('/news/search', name: 'app_news_search_page', methods: ['GET'])]
    public function __invoke(Request $request, ContentEntryRepository $entries): Response
    {
        $parameters = $request->query->all();
        $rawQuery = $parameters['q'] ?? '';
        if (!is_string($rawQuery) || mb_strlen($rawQuery, 'UTF-8') > self::MAX_QUERY_LENGTH) {
            throw $this->createNotFoundException();
        }

        $query = trim($rawQuery);
        $hasSearch = mb_strlen($query, 'UTF-8') >= 2;
        $results = $hasSearch
            ? $entries->searchPublished($query, limit: self::MAX_RESULTS, type: ContentEntry::TYPE_NEWS)
            : [];

        $response = $this->render('content/news_search.html.twig', [
            'query' => $query,
            'has_search' => $hasSearch,
            'results' => $results,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->headers->set('X-Robots-Tag', 'noindex, follow');

        return $response;
    }
}

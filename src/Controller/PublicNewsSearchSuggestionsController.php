<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicNewsSearchSuggestionsController extends AbstractController
{
    private const MAX_QUERY_LENGTH = 80;
    private const MAX_SUGGESTIONS = 5;
    private const CACHE_SECONDS = 30;

    #[Route('/news/search-suggestions.json', name: 'app_news_search_suggestions', methods: ['GET'])]
    public function __invoke(Request $request, ContentEntryRepository $entries): Response
    {
        $parameters = $request->query->all();
        $rawQuery = $parameters['q'] ?? '';
        if (!is_string($rawQuery) || mb_strlen($rawQuery, 'UTF-8') > self::MAX_QUERY_LENGTH) {
            throw $this->createNotFoundException();
        }

        $query = trim($rawQuery);
        $matches = mb_strlen($query, 'UTF-8') >= 2
            ? $entries->searchPublished($query, limit: self::MAX_SUGGESTIONS, type: ContentEntry::TYPE_NEWS)
            : [];

        $suggestions = array_map(fn (ContentEntry $entry): array => [
            'title' => $entry->getTitle(),
            'url' => $this->generateUrl('app_news_show', ['slug' => $entry->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
        ], $matches);
        $etagState = [$query, $suggestions];
        $response = new JsonResponse([
            'query' => $query,
            'suggestions' => $suggestions,
        ]);
        $response->setPublic();
        $response->setMaxAge(self::CACHE_SECONDS);
        $response->setSharedMaxAge(self::CACHE_SECONDS);
        $response->setEtag(hash('sha256', json_encode($etagState, JSON_THROW_ON_ERROR)));
        $response->isNotModified($request);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }
}

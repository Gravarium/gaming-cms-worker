<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicContentController extends AbstractController
{
    public function __construct(private readonly ContentEntryRepository $entries)
    {
    }

    #[Route('/news', name: 'app_news_index', methods: ['GET'])]
    public function news(Request $request): Response
    {
        $entries = $this->entries->findPublishedNews();
        $response = $this->render('content/news.html.twig', ['entries' => $entries]);

        return $this->cache($request, $response, $this->fingerprint($entries), 60);
    }

    #[Route('/search', name: 'app_content_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $query = mb_substr(trim($request->query->getString('q')), 0, 100);
        $entries = $this->entries->searchPublished($query);
        $response = $this->render('content/search.html.twig', ['query' => $query, 'entries' => $entries]);

        return $this->cache($request, $response, hash('sha256', $query.'|'.$this->fingerprint($entries)), 60);
    }

    #[Route('/news/{slug}', name: 'app_news_show', methods: ['GET'])]
    public function showNews(string $slug, Request $request): Response
    {
        return $this->show($slug, ContentEntry::TYPE_NEWS, $request);
    }

    #[Route('/page/{slug}', name: 'app_page_show', methods: ['GET'])]
    public function showPage(string $slug, Request $request): Response
    {
        return $this->show($slug, ContentEntry::TYPE_PAGE, $request);
    }

    private function show(string $slug, string $type, Request $request): Response
    {
        $entry = $this->entries->findPublishedBySlug($slug, $type);
        if ($entry === null) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('content/show.html.twig', ['entry' => $entry]);

        return $this->cache($request, $response, hash('sha256', $entry->getId().'|'.$entry->getUpdatedAt()->format('U.u')), 300, $entry->getUpdatedAt());
    }

    /** @param list<ContentEntry> $entries */
    private function fingerprint(array $entries): string
    {
        return hash('sha256', implode('|', array_map(
            static fn (ContentEntry $entry): string => $entry->getId().':'.$entry->getUpdatedAt()->format('U.u'),
            $entries,
        )));
    }

    private function cache(Request $request, Response $response, string $etag, int $seconds, ?\DateTimeImmutable $lastModified = null): Response
    {
        $response->setPublic();
        $response->setMaxAge($seconds);
        $response->setSharedMaxAge($seconds);
        $response->setEtag($etag);
        if ($lastModified !== null) {
            $response->setLastModified($lastModified);
        }
        $response->isNotModified($request);

        return $response;
    }
}

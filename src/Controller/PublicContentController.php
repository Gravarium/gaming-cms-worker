<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Repository\CategoryRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\ContentRedirectRepository;
use App\Repository\ContentTagRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicContentController extends AbstractController
{
    private const PAGE_SIZE = 20;
    public function __construct(private readonly ContentEntryRepository $entries, private readonly CategoryRepository $categories, private readonly ContentTagRepository $tags, private readonly ContentRedirectRepository $redirects) {}

    #[Route('/news', name: 'app_news_index', methods: ['GET'])]
    public function news(Request $request): Response
    {
        return $this->renderNewsList($request, $this->resolveCategory($request->query->getString('category')), $this->resolveTag($request->query->getString('tag')));
    }
    #[Route('/news/category/{slug}', name: 'app_news_category', methods: ['GET'])]
    public function category(string $slug, Request $request): Response
    {
        $category = $this->categories->findOneBy(['slug' => $slug]); if (!$category instanceof Category) { throw $this->createNotFoundException(); }
        return $this->renderNewsList($request, $category, null);
    }
    #[Route('/news/tag/{slug}', name: 'app_news_tag', methods: ['GET'])]
    public function tag(string $slug, Request $request): Response
    {
        $tag = $this->tags->findOneBySlug($slug); if (!$tag instanceof ContentTag) { throw $this->createNotFoundException(); }
        return $this->renderNewsList($request, null, $tag);
    }
    #[Route('/search', name: 'app_content_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        $query = mb_substr(trim($request->query->getString('q')), 0, 100); $entries = $this->entries->searchPublished($query);
        $response = $this->render('content/search.html.twig', ['query' => $query, 'entries' => $entries]);
        return $this->cache($request, $response, hash('sha256', $query.'|'.$this->fingerprint($entries)), 60, null, $entries);
    }
    #[Route('/news/{slug}', name: 'app_news_show', priority: -10, methods: ['GET'])]
    public function showNews(string $slug, Request $request): Response { return $this->show($slug, ContentEntry::TYPE_NEWS, $request); }
    #[Route('/page/{slug}', name: 'app_page_show', methods: ['GET'])]
    public function showPage(string $slug, Request $request): Response { return $this->show($slug, ContentEntry::TYPE_PAGE, $request); }
    #[Route('/feeds/news.xml', name: 'app_news_feed_rss', methods: ['GET'])]
    public function rss(Request $request): Response
    {
        $entries = $this->entries->findPublishedNews(50); $response = $this->render('content/feed.xml.twig', ['entries' => $entries]);
        $response->headers->set('Content-Type', 'application/rss+xml; charset=UTF-8');
        return $this->cache($request, $response, $this->fingerprint($entries), 300, null, $entries);
    }
    #[Route('/feeds/news.json', name: 'app_news_feed_json', methods: ['GET'])]
    public function jsonFeed(Request $request): JsonResponse
    {
        $entries = $this->entries->findPublishedNews(50);
        $items = array_map(fn (ContentEntry $entry): array => [
            'id' => (string) $entry->getId(),
            'url' => $this->generateUrl('app_news_show', ['slug' => $entry->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
            'title' => $entry->getTitle(), 'summary' => $entry->getExcerpt(), 'date_published' => $entry->getPublishedAt()?->format(DATE_ATOM),
            'tags' => array_values(array_map(static fn (ContentTag $tag): string => $tag->getName(), $entry->getTags()->toArray())),
        ], $entries);
        $response = new JsonResponse(['version' => 'https://jsonfeed.org/version/1.1', 'title' => 'News', 'items' => $items]);
        $cacheLifetime = $this->cacheLifetime($entries, 300);
        $response->setPublic(); $response->setMaxAge($cacheLifetime); $response->setSharedMaxAge($cacheLifetime); $response->setEtag($this->fingerprint($entries)); $response->isNotModified($request);
        return $response;
    }
    #[Route('/sitemap.xml', name: 'app_content_sitemap', methods: ['GET'])]
    public function sitemap(Request $request): Response
    {
        $entries = $this->entries->findPublishedAll(); $response = $this->render('content/sitemap.xml.twig', ['entries' => $entries]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        return $this->cache($request, $response, $this->fingerprint($entries), 900, null, $entries);
    }

    private function renderNewsList(Request $request, ?Category $category, ?ContentTag $tag): Response
    {
        $page = max(1, min(10000, $request->query->getInt('page', 1))); $total = $this->entries->countPublishedNews($category, $tag); $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $pages && $total > 0) { throw $this->createNotFoundException(); }
        $entries = $this->entries->findPublishedNews(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE, $category, $tag);
        $featured = $page === 1 && $category === null && $tag === null ? $this->entries->findFeaturedNews() : [];
        $response = $this->render('content/news.html.twig', ['entries' => $entries, 'featured' => $featured, 'category' => $category, 'tag' => $tag, 'page' => $page, 'pages' => $pages, 'total' => $total]);
        $visibleEntries = array_merge($featured, $entries);
        return $this->cache($request, $response, hash('sha256', $page.'|'.($category?->getSlug() ?? '').'|'.($tag?->getSlug() ?? '').'|'.$this->fingerprint($visibleEntries)), 60, null, $visibleEntries);
    }
    private function show(string $slug, string $type, Request $request): Response
    {
        $entry = $this->entries->findPublishedBySlug($slug, $type);
        if ($entry === null) {
            $redirect = $this->redirects->findTarget($type, $slug);
            if ($redirect !== null && $redirect->getEntry()->isPublished()) {
                $route = $type === ContentEntry::TYPE_NEWS ? 'app_news_show' : 'app_page_show';
                return new RedirectResponse($this->generateUrl($route, ['slug' => $redirect->getEntry()->getSlug()]), Response::HTTP_MOVED_PERMANENTLY);
            }
            throw $this->createNotFoundException();
        }
        $related = $this->entries->findRelated($entry);
        $response = $this->render('content/show.html.twig', ['entry' => $entry, 'related' => $related, 'preview' => false]);
        if ($type === ContentEntry::TYPE_PAGE) { $response->headers->set('Cache-Control', 'private, no-store'); return $response; }
        return $this->cache($request, $response, hash('sha256', $entry->getId().'|'.$entry->getUpdatedAt()->format('U.u')), 300, $entry->getUpdatedAt(), [$entry, ...$related]);
    }
    private function resolveCategory(string $slug): ?Category
    {
        if ($slug === '') { return null; } $category = $this->categories->findOneBy(['slug' => $slug]); if (!$category instanceof Category) { throw $this->createNotFoundException(); } return $category;
    }
    private function resolveTag(string $slug): ?ContentTag
    {
        if ($slug === '') { return null; } $tag = $this->tags->findOneBySlug($slug); if (!$tag instanceof ContentTag) { throw $this->createNotFoundException(); } return $tag;
    }
    /** @param list<ContentEntry> $entries */
    private function fingerprint(array $entries): string { return hash('sha256', implode('|', array_map(static fn (ContentEntry $entry): string => $entry->getId().':'.$entry->getUpdatedAt()->format('U.u'), $entries))); }
    /** @param list<ContentEntry> $entries */
    private function cache(Request $request, Response $response, string $etag, int $seconds, ?\DateTimeImmutable $lastModified = null, array $entries = []): Response
    {
        $seconds = $this->cacheLifetime($entries, $seconds);
        $response->setPublic(); $response->setMaxAge($seconds); $response->setSharedMaxAge($seconds); $response->setEtag($etag); if ($lastModified !== null) { $response->setLastModified($lastModified); } $response->isNotModified($request); return $response;
    }

    /** @param list<ContentEntry> $entries */
    private function cacheLifetime(array $entries, int $maximum): int
    {
        $now = new \DateTimeImmutable();
        $lifetime = $maximum;

        foreach ($entries as $entry) {
            $deadline = $entry->getScheduledUnpublishAt();
            if ($deadline === null) {
                continue;
            }

            $remaining = $deadline->getTimestamp() - $now->getTimestamp();
            if ((int) $deadline->format('u') < (int) $now->format('u')) {
                --$remaining;
            }
            $lifetime = min($lifetime, max(0, $remaining));
        }

        return $lifetime;
    }
}

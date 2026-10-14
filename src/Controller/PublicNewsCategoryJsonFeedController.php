<?php

declare(strict_types=1);

namespace App\\Controller;

use App\\Entity\\Category;
use App\\Entity\\ContentEntry;
use App\\Entity\\ContentTag;
use App\\Repository\\CategoryRepository;
use App\\Repository\\ContentEntryRepository;
use Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController;
use Symfony\\Component\\HttpFoundation\\JsonResponse;
use Symfony\\Component\\HttpFoundation\\Request;
use Symfony\\Component\\HttpFoundation\\Response;
use Symfony\\Component\\HttpKernel\\EventListener\\AbstractSessionListener;
use Symfony\\Component\\Routing\\Attribute\\Route;
use Symfony\\Component\\Routing\\Generator\\UrlGeneratorInterface;

final class PublicNewsCategoryJsonFeedController extends AbstractController
{
    private const CACHE_SECONDS = 60;

    #[Route('/news/category/{slug}/feed.json', name: 'app_news_category_json_feed', methods: ['GET'])]
    public function __invoke(
        string $slug,
        Request $request,
        CategoryRepository $categories,
        ContentEntryRepository $entries,
    ): JsonResponse {
        $category = $categories->findOneBy(['slug' => $slug]);
        if (!$category instanceof Category) {
            throw $this->createNotFoundException();
        }

        /** @var list<ContentEntry> $news */
        $news = $entries->findPublishedNews(50, 0, $category);
        $categoryUrl = $this->generateUrl('app_news_category', ['slug' => $category->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);
        $feedUrl = $this->generateUrl('app_news_category_json_feed', ['slug' => $category->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);
        $items = array_map(function (ContentEntry $entry): array {
            $url = $this->generateUrl('app_news_show', ['slug' => $entry->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL);

            return [
                'id' => $url,
                'url' => $url,
                'title' => $entry->getTitle(),
                'summary' => $entry->getExcerpt() ?? '',
                'date_published' => $entry->getPublishedAt()?->format(DATE_ATOM),
                'tags' => array_values(array_map(
                    static fn (ContentTag $tag): string => $tag->getName(),
                    $entry->getTags()->toArray(),
                )),
            ];
        }, $news);

        $response = new JsonResponse([
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => $category->getName(),
            'home_page_url' => $categoryUrl,
            'feed_url' => $feedUrl,
            'description' => $category->getDescription() ?? '',
            'items' => $items,
        ], Response::HTTP_OK, ['Content-Type' => 'application/feed+json; charset=UTF-8']);

        $lifetime = $this->cacheLifetime($news);
        $response->setPublic();
        $response->setMaxAge($lifetime);
        $response->setSharedMaxAge($lifetime);
        $etagState = [$category->getSlug(), $category->getName(), $category->getDescription()];
        foreach ($news as $entry) {
            $etagState[] = [$entry->getId(), $entry->getUpdatedAt()->format('U.u')];
        }
        $response->setEtag(hash('sha256', json_encode($etagState, JSON_THROW_ON_ERROR)));
        $response->isNotModified($request);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    /** @param list<ContentEntry> $entries */
    private function cacheLifetime(array $entries): int
    {
        $now = new DateTimeImmutable();
        $lifetime = self::CACHE_SECONDS;
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

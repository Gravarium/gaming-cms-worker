<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Repository\CategoryRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\SiteSettingsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicNewsCategoryFeedController extends AbstractController
{
    private const CACHE_SECONDS = 60;

    #[Route('/news/category/{slug}/feed.xml', name: 'app_news_category_feed_rss', methods: ['GET'], stateless: true)]
    public function __invoke(
        string $slug,
        Request $request,
        CategoryRepository $categories,
        ContentEntryRepository $entries,
        SiteSettingsRepository $siteSettings,
    ): Response {
        $category = $categories->findOneBy(['slug' => $slug]);
        if (!$category instanceof Category) {
            throw $this->createNotFoundException();
        }

        /** @var list<ContentEntry> $items */
        $items = $entries->findPublishedNews(50, 0, $category);
        $response = $this->render('content/news_category_feed.xml.twig', [
            'category' => $category,
            'entries' => $items,
            'site' => $siteSettings->current(),
        ]);
        $response->headers->set('Content-Type', 'application/rss+xml; charset=UTF-8');
        $response->setPublic();
        $lifetime = $this->cacheLifetime($items);
        $response->setMaxAge($lifetime);
        $response->setSharedMaxAge($lifetime);
        $etagInput = [$category->getSlug(), $category->getName(), $category->getDescription()];
        foreach ($items as $item) {
            $etagInput[] = [$item->getId(), $item->getUpdatedAt()->format('U.u')];
        }
        $response->setEtag(hash('sha256', json_encode($etagInput, JSON_THROW_ON_ERROR)));
        $response->isNotModified($request);

        return $response;
    }

    /** @param list<ContentEntry> $entries */
    private function cacheLifetime(array $entries): int
    {
        $now = new \DateTimeImmutable();
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

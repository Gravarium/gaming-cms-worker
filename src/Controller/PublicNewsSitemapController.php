<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicNewsSitemapController extends AbstractController
{
    private const CACHE_SECONDS = 60;
    private const MAX_ITEMS = 100;
    private const NEWS_WINDOW_HOURS = 48;

    #[Route('/news-sitemap.xml', name: 'app_news_sitemap', methods: ['GET'])]
    public function __invoke(Request $request, ContentEntryRepository $entries): Response
    {
        $now = new DateTimeImmutable();
        $cutoff = $now->modify('-'.self::NEWS_WINDOW_HOURS.' hours');

        /** @var list<ContentEntry> $candidates */
        $candidates = $entries->findPublishedNews(self::MAX_ITEMS);
        $items = array_values(array_filter($candidates, static function (ContentEntry $entry) use ($cutoff, $now): bool {
            $publishedAt = $entry->getPublishedAt();

            return !$entry->isNoIndex()
                && $publishedAt !== null
                && $publishedAt >= $cutoff
                && $publishedAt <= $now;
        }));

        $response = $this->render('content/news_sitemap.xml.twig', [
            'entries' => $items,
            'language' => substr($request->getLocale(), 0, 2),
        ]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->setPublic();
        $lifetime = $this->cacheLifetime($items, $now);
        $response->setMaxAge($lifetime);
        $response->setSharedMaxAge($lifetime);

        $etagState = [];
        foreach ($items as $item) {
            $etagState[] = [$item->getId(), $item->getUpdatedAt()->format('U.u')];
        }
        $response->setEtag(hash('sha256', json_encode($etagState, JSON_THROW_ON_ERROR)));
        $response->isNotModified($request);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    /** @param list<ContentEntry> $entries */
    private function cacheLifetime(array $entries, DateTimeImmutable $now): int
    {
        $lifetime = self::CACHE_SECONDS;
        foreach ($entries as $entry) {
            $publishedAt = $entry->getPublishedAt();
            if ($publishedAt !== null) {
                $expiresAt = $publishedAt->modify('+'.self::NEWS_WINDOW_HOURS.' hours');
                $lifetime = min($lifetime, max(0, $expiresAt->getTimestamp() - $now->getTimestamp()));
            }

            $unpublishAt = $entry->getScheduledUnpublishAt();
            if ($unpublishAt !== null) {
                $lifetime = min($lifetime, max(0, $unpublishAt->getTimestamp() - $now->getTimestamp()));
            }
        }

        return $lifetime;
    }
}

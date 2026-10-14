<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use App\Repository\SiteSettingsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicNewsAtomFeedController extends AbstractController
{
    private const MAX_ENTRIES = 50;
    private const MAX_CACHE_SECONDS = 300;

    #[Route('/feeds/news.atom', name: 'app_news_atom_feed', methods: ['GET'])]
    public function __invoke(
        Request $request,
        ContentEntryRepository $entries,
        SiteSettingsRepository $siteSettings,
    ): Response {
        $publishedEntries = $entries->findPublishedNews(self::MAX_ENTRIES);
        $site = $siteSettings->current();
        $feedUpdatedAt = $this->latestUpdatedAt($publishedEntries);
        $newsUrl = $this->generateUrl('app_news_index', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $selfUrl = $this->generateUrl('app_news_atom_feed', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $response = $this->render('content/news_atom_feed.xml.twig', [
            'entries' => $publishedEntries,
            'site' => $site,
            'feed_updated_at' => $feedUpdatedAt,
            'news_url' => $newsUrl,
            'self_url' => $selfUrl,
        ]);
        $response->headers->set('Content-Type', 'application/atom+xml; charset=UTF-8');
        $cacheLifetime = $this->cacheLifetime($publishedEntries);
        $response->setPublic();
        $response->setMaxAge($cacheLifetime);
        $response->setSharedMaxAge($cacheLifetime);
        $response->setEtag(hash('sha256', (string) $response->getContent()));
        $response->isNotModified($request);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    /** @param list<ContentEntry> $entries */
    private function latestUpdatedAt(array $entries): \DateTimeImmutable
    {
        $latest = null;
        foreach ($entries as $entry) {
            $updatedAt = $entry->getUpdatedAt();
            if ($latest === null || $updatedAt > $latest) {
                $latest = $updatedAt;
            }
        }

        return $latest ?? new \DateTimeImmutable('1970-01-01T00:00:00+00:00');
    }

    /** @param list<ContentEntry> $entries */
    private function cacheLifetime(array $entries): int
    {
        $now = new \DateTimeImmutable();
        $lifetime = self::MAX_CACHE_SECONDS;
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

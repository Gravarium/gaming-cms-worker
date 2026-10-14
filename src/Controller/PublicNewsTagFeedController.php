<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Repository\ContentEntryRepository;
use App\Repository\ContentTagRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

final class PublicNewsTagFeedController extends AbstractController
{
    private const CACHE_SECONDS = 60;

    #[Route('/news/tag/{slug}/feed.xml', name: 'app_news_tag_feed_rss', methods: ['GET'])]
    public function __invoke(
        string $slug,
        Request $request,
        ContentTagRepository $tags,
        ContentEntryRepository $entries,
    ): Response {
        $tag = $tags->findOneBySlug($slug);
        if (!$tag instanceof ContentTag) {
            throw $this->createNotFoundException();
        }

        /** @var list<ContentEntry> $items */
        $items = $entries->findPublishedNews(50, 0, null, $tag);
        $response = $this->render('content/news_tag_feed.xml.twig', [
            'tag' => $tag,
            'entries' => $items,
        ]);
        $response->headers->set('Content-Type', 'application/rss+xml; charset=UTF-8');
        $response->setPublic();
        $lifetime = $this->cacheLifetime($items);
        $response->setMaxAge($lifetime);
        $response->setSharedMaxAge($lifetime);
        $etagState = [$tag->getSlug(), $tag->getName(), $tag->getDescription()];
        foreach ($items as $item) {
            $etagState[] = [$item->getId(), $item->getUpdatedAt()->format('U.u')];
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

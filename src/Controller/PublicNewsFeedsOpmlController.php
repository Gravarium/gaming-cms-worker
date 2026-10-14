<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Category;
use App\Entity\ContentTag;
use App\Repository\CategoryRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\ContentTagRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PublicNewsFeedsOpmlController extends AbstractController
{
    private const CACHE_SECONDS = 60;
    private const MAX_PER_TYPE = 50;

    #[Route('/news/feeds.opml', name: 'app_news_feeds_opml', methods: ['GET'])]
    public function __invoke(
        Request $request,
        CategoryRepository $categories,
        ContentTagRepository $tags,
        ContentEntryRepository $entries,
    ): Response {
        $categoryFeeds = [];
        $categoryRows = $categories->findAll();
        usort($categoryRows, static fn (Category $left, Category $right): int => strcmp($left->getSlug(), $right->getSlug()));
        foreach (array_slice($categoryRows, 0, self::MAX_PER_TYPE) as $category) {
            if ($entries->findPublishedNews(1, 0, $category) === []) {
                continue;
            }

            $categoryFeeds[] = [
                'title' => $category->getName(),
                'feed_url' => $this->generateUrl('app_news_category_feed_rss', ['slug' => $category->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                'archive_url' => $this->generateUrl('app_news_category', ['slug' => $category->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
            ];
        }

        $tagFeeds = [];
        $tagRows = $tags->findAll();
        usort($tagRows, static fn (ContentTag $left, ContentTag $right): int => strcmp($left->getSlug(), $right->getSlug()));
        foreach (array_slice($tagRows, 0, self::MAX_PER_TYPE) as $tag) {
            if ($entries->findPublishedNews(1, 0, null, $tag) === []) {
                continue;
            }

            $tagFeeds[] = [
                'title' => $tag->getName(),
                'feed_url' => $this->generateUrl('app_news_tag_feed_rss', ['slug' => $tag->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
                'archive_url' => $this->generateUrl('app_news_tag', ['slug' => $tag->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
            ];
        }

        $response = $this->render('content/news_feeds_opml.xml.twig', [
            'categoryFeeds' => $categoryFeeds,
            'tagFeeds' => $tagFeeds,
        ]);
        $response->headers->set('Content-Type', 'text/x-opml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(self::CACHE_SECONDS);
        $response->setSharedMaxAge(self::CACHE_SECONDS);
        $response->setEtag(hash('sha256', json_encode([$categoryFeeds, $tagFeeds], JSON_THROW_ON_ERROR)));
        $response->isNotModified($request);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }
}

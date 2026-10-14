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

final class PublicNewsTaxonomySitemapController extends AbstractController
{
    private const CACHE_SECONDS = 60;
    private const MAX_PER_TYPE = 100;

    #[Route('/news-taxonomy-sitemap.xml', name: 'app_news_taxonomy_sitemap', methods: ['GET'])]
    public function __invoke(
        Request $request,
        CategoryRepository $categories,
        ContentTagRepository $tags,
        ContentEntryRepository $entries,
    ): Response {
        $items = [];
        $categoryRows = $categories->findAll();
        usort($categoryRows, static fn (Category $left, Category $right): int => strcmp($left->getSlug(), $right->getSlug()));
        foreach (array_slice($categoryRows, 0, self::MAX_PER_TYPE) as $category) {
            if ($entries->findPublishedNews(1, 0, $category) === []) {
                continue;
            }

            $items[] = [
                'type' => 'category',
                'slug' => $category->getSlug(),
                'url' => $this->generateUrl('app_news_category', ['slug' => $category->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
            ];
        }

        $tagRows = $tags->findAll();
        usort($tagRows, static fn (ContentTag $left, ContentTag $right): int => strcmp($left->getSlug(), $right->getSlug()));
        foreach (array_slice($tagRows, 0, self::MAX_PER_TYPE) as $tag) {
            if ($entries->findPublishedNews(1, 0, null, $tag) === []) {
                continue;
            }

            $items[] = [
                'type' => 'tag',
                'slug' => $tag->getSlug(),
                'url' => $this->generateUrl('app_news_tag', ['slug' => $tag->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
            ];
        }

        $response = $this->render('content/news_taxonomy_sitemap.xml.twig', ['items' => $items]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(self::CACHE_SECONDS);
        $response->setSharedMaxAge(self::CACHE_SECONDS);
        $etagState = array_map(static fn (array $item): array => [$item['type'], $item['slug']], $items);
        $response->setEtag(hash('sha256', json_encode($etagState, JSON_THROW_ON_ERROR)));
        $response->isNotModified($request);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }
}

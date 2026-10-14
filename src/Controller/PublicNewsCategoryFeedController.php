<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\SiteSettingsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicNewsCategoryFeedController extends AbstractController
{
    #[Route('/news/category/{slug}/feed.xml', name: 'app_news_category_feed_rss', methods: ['GET'])]
    public function __invoke(
        string $slug,
        CategoryRepository $categories,
        ContentEntryRepository $entries,
        SiteSettingsRepository $siteSettings,
    ): Response {
        $category = $categories->findOneBy(['slug' => $slug]);
        if (!$category instanceof Category) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('content/news_category_feed.xml.twig', [
            'category' => $category,
            'entries' => $entries->findPublishedNews(50, 0, $category),
            'site' => $siteSettings->current(),
        ]);
        $response->headers->set('Content-Type', 'application/rss+xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(60);

        return $response;
    }
}

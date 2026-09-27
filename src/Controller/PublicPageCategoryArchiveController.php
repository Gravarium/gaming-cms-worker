<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Category;
use App\PageCategoryArchive\PublicPageCategoryArchiveQuery;
use App\Repository\CategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/pages')]
final class PublicPageCategoryArchiveController extends AbstractController
{
    private const DIRECTORY_PAGE_SIZE = 20;
    private const ARCHIVE_PAGE_SIZE = 20;

    public function __construct(
        private readonly PublicPageCategoryArchiveQuery $pages,
        private readonly CategoryRepository $categories,
    ) {
    }

    #[Route('/categories', name: 'app_content_page_category_directory', methods: ['GET'])]
    public function directory(Request $request): Response
    {
        $page = max(1, min(10000, $request->query->getInt('page', 1)));
        $total = $this->pages->countCategoriesWithPublicPages();
        $pageCount = max(1, (int) ceil($total / self::DIRECTORY_PAGE_SIZE));
        if ($page > $pageCount) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('page_category_archive/directory.html.twig', [
            'categories' => $this->pages->findCategoriesWithPublicPages(self::DIRECTORY_PAGE_SIZE, ($page - 1) * self::DIRECTORY_PAGE_SIZE),
            'page' => $page,
            'pages' => $pageCount,
            'total' => $total,
        ]);

        return $this->publicCache($response);
    }

    #[Route('/category/{slug}', name: 'app_content_page_category_archive', methods: ['GET'])]
    public function archive(string $slug, Request $request): Response
    {
        $category = $this->categories->findOneBy(['slug' => $slug]);
        if (!$category instanceof Category) {
            throw $this->createNotFoundException();
        }

        $total = $this->pages->countPublicPages($category);
        if ($total === 0) {
            throw $this->createNotFoundException();
        }

        $page = max(1, min(10000, $request->query->getInt('page', 1)));
        $pageCount = max(1, (int) ceil($total / self::ARCHIVE_PAGE_SIZE));
        if ($page > $pageCount) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('page_category_archive/archive.html.twig', [
            'category' => $category,
            'entries' => $this->pages->findPublicPages($category, self::ARCHIVE_PAGE_SIZE, ($page - 1) * self::ARCHIVE_PAGE_SIZE),
            'page' => $page,
            'pages' => $pageCount,
            'total' => $total,
        ]);

        return $this->publicCache($response);
    }

    private function publicCache(Response $response): Response
    {
        $response->setPublic();
        $response->setMaxAge(60);
        $response->setSharedMaxAge(60);

        return $response;
    }
}

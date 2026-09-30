<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PublicCategoryDirectoryController extends AbstractController
{
    private const PAGE_SIZE = 20;
    private const MAX_PAGE = 10000;

    public function __construct(private readonly CategoryRepository $categories)
    {
    }

    #[Route('/news/categories', name: 'app_news_category_directory', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $pageValue = $request->query->getString('page', '1');
        if (preg_match('/\\A[1-9][0-9]{0,4}\\z/', $pageValue) !== 1) {
            throw new BadRequestHttpException('The page parameter must be a positive integer.');
        }

        $page = (int) $pageValue;
        if ($page > self::MAX_PAGE) {
            throw $this->createNotFoundException();
        }

        $total = $this->categories->count([]);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $pages) {
            throw $this->createNotFoundException();
        }

        return $this->render('category/index.html.twig', [
            'categories' => $this->categories->findBy([], ['name' => 'ASC', 'id' => 'ASC'], self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'page' => $page,
            'pages' => $pages,
        ]);
    }
}

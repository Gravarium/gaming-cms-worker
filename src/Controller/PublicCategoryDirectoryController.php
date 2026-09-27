<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicCategoryDirectoryController extends AbstractController
{
    public function __construct(private readonly CategoryRepository $categories)
    {
    }

    #[Route('/news/categories', name: 'app_news_category_directory', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('category/index.html.twig', [
            'categories' => $this->categories->findBy([], ['name' => 'ASC', 'id' => 'ASC']),
        ]);
    }
}

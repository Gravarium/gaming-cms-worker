<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\VideoCategoryRepository;
use App\Repository\VideoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VideoCategoryController extends AbstractController
{
    public function __construct(
        private readonly VideoCategoryRepository $categories,
        private readonly VideoRepository $videos,
    ) {
    }

    #[Route('/videos/categories', name: 'app_video_category_index', methods: ['GET'], priority: 10)]
    public function index(): Response
    {
        return $this->render('video_category/index.html.twig', [
            'categories' => $this->categories->findEnabled(),
        ]);
    }

    #[Route('/videos/categories/{slug}', name: 'app_video_category_show', methods: ['GET'])]
    public function show(string $slug): Response
    {
        $category = $this->categories->findOneBy(['slug' => $slug, 'enabled' => true]);
        if ($category === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('video_category/show.html.twig', [
            'category' => $category,
            'videos' => $this->videos->findPublished($category),
        ]);
    }
}

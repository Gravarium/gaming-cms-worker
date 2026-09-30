<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\VideoCategoryRepository;
use App\VideoCategory\PublicVideoCategoryVideosQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class VideoCategoryController extends AbstractController
{
    public function __construct(
        private readonly VideoCategoryRepository $categories,
        private readonly PublicVideoCategoryVideosQuery $videos,
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
    public function show(Request $request, string $slug): Response
    {
        $category = $this->categories->findOneBy(['slug' => $slug, 'enabled' => true]);
        if ($category === null) {
            throw $this->createNotFoundException();
        }

        $parameters = $request->query->all();
        $rawPage = $parameters['page'] ?? '1';
        if (!is_string($rawPage) || preg_match('/\A[1-9][0-9]{0,3}\z/', $rawPage) !== 1) {
            throw new BadRequestHttpException('The page must be a positive integer.');
        }

        $page = (int) $rawPage;
        if ($page > PublicVideoCategoryVideosQuery::MAX_PAGE) {
            throw new BadRequestHttpException('The requested page is outside the supported range.');
        }

        $now = new \DateTimeImmutable();
        $total = $this->videos->countPublishedInCategory($category, $now);
        $totalPages = min(
            PublicVideoCategoryVideosQuery::MAX_PAGE,
            max(1, (int) ceil($total / PublicVideoCategoryVideosQuery::PAGE_SIZE)),
        );
        if ($page > $totalPages) {
            throw $this->createNotFoundException();
        }

        return $this->render('@video_category_pagination/public/show.html.twig', [
            'category' => $category,
            'videos' => $this->videos->pagePublishedInCategory($category, $now, $page),
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }
}

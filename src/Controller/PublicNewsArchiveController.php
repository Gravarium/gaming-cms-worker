<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PublicNewsArchiveRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicNewsArchiveController extends AbstractController
{
    private const PAGE_SIZE = 20;

    public function __construct(private readonly PublicNewsArchiveRepository $archive)
    {
    }

    #[Route('/news/archive', name: 'app_news_archive_index', methods: ['GET'])]
    public function index(): Response
    {
        $response = $this->render('content/news_archive.html.twig', [
            'periods' => $this->archive->availablePeriods(new \DateTimeImmutable()),
        ]);

        return $this->cachePublicResponse($response);
    }

    #[Route(
        '/news/archive/{year}/{month}',
        name: 'app_news_archive_month',
        requirements: ['year' => '\d{4}', 'month' => '\d{2}'],
        methods: ['GET'],
    )]
    public function month(int $year, int $month, Request $request): Response
    {
        if ($year < 1 || $month < 1 || $month > 12) {
            throw $this->createNotFoundException();
        }

        $page = $request->query->getInt('page', 1);
        if ($page < 1 || $page > 10000) {
            throw $this->createNotFoundException();
        }

        $now = new \DateTimeImmutable();
        $total = $this->archive->countForPeriod($year, $month, $now);
        if ($total === 0) {
            throw $this->createNotFoundException();
        }

        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $pages) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('content/news_archive.html.twig', [
            'periods' => [],
            'year' => $year,
            'month' => $month,
            'entries' => $this->archive->findForPeriod(
                $year,
                $month,
                $now,
                ($page - 1) * self::PAGE_SIZE,
                self::PAGE_SIZE,
            ),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);

        return $this->cachePublicResponse($response);
    }

    private function cachePublicResponse(Response $response): Response
    {
        $response->setPublic();
        $response->setMaxAge(60);

        return $response;
    }
}

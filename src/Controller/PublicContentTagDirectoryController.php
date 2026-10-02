<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SiteSettingsRepository;
use App\Widget\Content\PublicContentTagQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PublicContentTagDirectoryController extends AbstractController
{
    private const PAGE_SIZE = 20;
    private const MAX_PAGE = 10000;

    public function __construct(
        private readonly PublicContentTagQuery $tags,
        private readonly SiteSettingsRepository $siteSettings,
    ) {
    }

    #[Route('/news/tags', name: 'app_news_tag_directory', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $parameters = $request->query->all();
        $rawPage = $parameters['page'] ?? '1';
        if (!is_string($rawPage) || preg_match('/\A[1-9][0-9]{0,4}\z/', $rawPage) !== 1) {
            throw new BadRequestHttpException('The page must be a positive integer.');
        }

        $page = (int) $rawPage;
        if ($page > self::MAX_PAGE) {
            throw new BadRequestHttpException('The requested page is outside the supported range.');
        }

        $total = $this->tags->countPublic();
        $totalPages = max(
            1,
            min(
                self::MAX_PAGE,
                intdiv($total, self::PAGE_SIZE) + ($total % self::PAGE_SIZE === 0 ? 0 : 1),
            ),
        );
        if ($page > $totalPages) {
            throw $this->createNotFoundException();
        }

        return $this->render('content_tag/index.html.twig', [
            'tags' => $this->tags->findPublicPage(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'site' => $this->siteSettings->current(),
            'page' => $page,
            'pages' => $totalPages,
            'total' => $total,
        ]);
    }
}

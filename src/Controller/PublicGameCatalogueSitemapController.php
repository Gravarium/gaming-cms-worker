<?php

declare(strict_types=1);

namespace App\Controller;

use App\GameCatalogueSitemap\PublicGameCatalogueSitemapQuery;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PublicGameCatalogueSitemapController extends AbstractController
{
    public function __construct(
        private readonly PublicGameCatalogueSitemapQuery $games,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/sitemap-games.xml', name: 'app_gaming_game_sitemap_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->assertAvailable();
        $totalPages = $this->totalPages();

        if ($totalPages > PublicGameCatalogueSitemapQuery::MAX_SITEMAP_PAGES) {
            throw new ServiceUnavailableHttpException(null, 'The public Game Catalogue exceeds the supported sitemap index size.');
        }

        $response = $this->render('sitemap/games_index.xml.twig', [
            'totalPages' => $totalPages,
        ]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }

    #[Route('/sitemap-games/{page}.xml', name: 'app_gaming_game_sitemap_page', requirements: ['page' => '[0-9]+'], methods: ['GET'])]
    public function page(string $page): Response
    {
        $this->assertAvailable();
        if (preg_match('/\A[1-9][0-9]{0,4}\z/', $page) !== 1) {
            throw new BadRequestHttpException('The sitemap page must be a positive integer.');
        }

        $pageNumber = (int) $page;
        if ($pageNumber > PublicGameCatalogueSitemapQuery::MAX_SITEMAP_PAGES) {
            throw $this->createNotFoundException();
        }

        $totalPages = $this->totalPages();
        if ($pageNumber > $totalPages) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('sitemap/games_page.xml.twig', [
            'slugs' => $this->games->findPublicGameSlugs($pageNumber),
        ]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }

    private function totalPages(): int
    {
        return max(1, (int) ceil(
            $this->games->countPublicGames() / PublicGameCatalogueSitemapQuery::PAGE_SIZE,
        ));
    }
}

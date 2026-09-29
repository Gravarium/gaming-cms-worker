<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\CmsModuleManager;
use App\Repository\PublicGameCatalogueSearchRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PublicGameCatalogueSearchController extends AbstractController
{
    private const MIN_QUERY_LENGTH = 2;
    private const MAX_QUERY_LENGTH = 100;

    public function __construct(
        private readonly PublicGameCatalogueSearchRepository $entries,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/game-search', name: 'app_public_game_catalogue_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        $parameters = $request->query->all();
        $rawQuery = $parameters['q'] ?? '';
        if (!is_string($rawQuery) || preg_match('//u', $rawQuery) !== 1) {
            throw new BadRequestHttpException('The search query must be a valid UTF-8 string.');
        }

        if (strlen($rawQuery) > self::MAX_QUERY_LENGTH * 4 || preg_match('/\p{Cc}/u', $rawQuery) === 1) {
            throw new BadRequestHttpException('The search query is too long or contains control characters.');
        }

        $query = trim($rawQuery);
        if ($query !== '') {
            $length = mb_strlen($query, 'UTF-8');
            if ($length < self::MIN_QUERY_LENGTH || $length > self::MAX_QUERY_LENGTH) {
                throw new BadRequestHttpException('The search query must contain between 2 and 100 characters.');
            }
        }

        $rawPage = $parameters['page'] ?? '1';
        if (!is_string($rawPage) || preg_match('/\A[1-9][0-9]{0,3}\z/', $rawPage) !== 1) {
            throw new BadRequestHttpException('The page must be a positive integer.');
        }

        $page = (int) $rawPage;
        if ($page > PublicGameCatalogueSearchRepository::MAX_PAGE || ($query === '' && $page !== 1)) {
            throw new BadRequestHttpException('The requested page is outside the supported range.');
        }

        $total = 0;
        $totalPages = 1;
        $entries = [];
        if ($query !== '') {
            $total = $this->entries->countMatches($query);
            $totalPages = min(
                PublicGameCatalogueSearchRepository::MAX_PAGE,
                max(1, (int) ceil($total / PublicGameCatalogueSearchRepository::PAGE_SIZE)),
            );
            if ($page > $totalPages) {
                throw $this->createNotFoundException();
            }

            $entries = $this->entries->findMatches($query, $page);
        }

        return $this->render('game_search/index.html.twig', [
            'query' => $query,
            'entries' => $entries,
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }
}

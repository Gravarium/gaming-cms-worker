<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentEntry;
use App\Repository\ContentRedirectRepository;
use App\Security\CmsPermission;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/content/redirects')]
#[IsGranted(CmsPermission::CONTENT)]
final class AdminContentRedirectController extends AbstractController
{
    private const PAGE_SIZE = 25;
    private const MAX_PAGE = 10000;
    private const MAX_SEARCH_LENGTH = 120;

    #[Route('', name: 'app_admin_content_redirect_index', methods: ['GET'])]
    public function index(Request $request, ContentRedirectRepository $redirects): Response
    {
        try {
            $filters = $this->parseFilters($request);
        } catch (InvalidArgumentException) {
            return $this->markPrivate(new Response('Ungültige Filterparameter.', Response::HTTP_BAD_REQUEST));
        }

        $total = $redirects->countAdminResults($filters['q'], $filters['type']);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($filters['page'], $pages);
        $items = $redirects->findAdminPage($filters['q'], $filters['type'], self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        $response = $this->render('admin/content_redirect/index.html.twig', [
            'redirects' => $items,
            'filters' => ['q' => $filters['q'], 'type' => $filters['type']],
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);

        return $this->markPrivate($response);
    }

    /**
     * @return array{q: string, type: string, page: int}
     */
    private function parseFilters(Request $request): array
    {
        /** @var array<string, mixed> $parameters */
        $parameters = $request->query->all();
        $query = $parameters['q'] ?? '';
        $type = $parameters['type'] ?? '';
        $rawPage = $parameters['page'] ?? null;

        if (!is_string($query) || !is_string($type) || ($rawPage !== null && !is_string($rawPage))) {
            throw new InvalidArgumentException('Filterwerte müssen einzelne Textwerte sein.');
        }

        if (!mb_check_encoding($query, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $query) === 1) {
            throw new InvalidArgumentException('Ungültiger Suchtext.');
        }

        $query = trim($query);
        if (mb_strlen($query, 'UTF-8') > self::MAX_SEARCH_LENGTH) {
            throw new InvalidArgumentException('Der Suchtext ist zu lang.');
        }

        if (!in_array($type, ['', ContentEntry::TYPE_NEWS, ContentEntry::TYPE_PAGE], true)) {
            throw new InvalidArgumentException('Unbekannter Inhaltstyp.');
        }

        $page = 1;
        if ($rawPage !== null) {
            if (strlen($rawPage) > 5 || preg_match('/\A[1-9][0-9]*\z/D', $rawPage) !== 1) {
                throw new InvalidArgumentException('Ungültige Seitennummer.');
            }

            $page = (int) $rawPage;
            if ($page > self::MAX_PAGE) {
                throw new InvalidArgumentException('Seitennummer außerhalb des erlaubten Bereichs.');
            }
        }

        return ['q' => $query, 'type' => $type, 'page' => $page];
    }

    private function markPrivate(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}

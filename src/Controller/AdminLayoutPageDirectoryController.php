<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AdminLayoutPageDirectoryRepository;
use App\Security\CmsPermission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(CmsPermission::SETTINGS)]
final class AdminLayoutPageDirectoryController extends AbstractController
{
    private const PAGE_SIZE = 25;
    private const MAX_QUERY_LENGTH = 100;

    public function __construct(private readonly AdminLayoutPageDirectoryRepository $pages)
    {
    }

    #[Route('/admin/layout/pages', name: 'app_admin_layout_pages', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $parameters = $request->query->all();
        $search = $parameters['q'] ?? '';
        $rawPage = $parameters['page'] ?? '1';

        if (
            !is_string($search)
            || !mb_check_encoding($search, 'UTF-8')
            || mb_strlen($search) > self::MAX_QUERY_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $search) === 1
            || !is_string($rawPage)
            || preg_match('/^[1-9][0-9]{0,8}$/D', $rawPage) !== 1
        ) {
            throw new BadRequestHttpException('Ungültige Seitensuche.');
        }

        $search = trim($search);
        $total = $this->pages->countPages($search);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $currentPage = min((int) $rawPage, $pageCount);
        $pages = $this->pages->findPages(
            $search,
            self::PAGE_SIZE,
            ($currentPage - 1) * self::PAGE_SIZE,
        );

        $response = $this->render('admin/layout/pages.html.twig', [
            'pages' => $pages,
            'query' => $search,
            'total' => $total,
            'currentPage' => $currentPage,
            'pageCount' => $pageCount,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}

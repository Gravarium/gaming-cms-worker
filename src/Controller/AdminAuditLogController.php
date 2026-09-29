<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/audit-log')]
#[IsGranted('CMS_AUDIT_VIEW')]
final class AdminAuditLogController extends AbstractController
{
    private const PAGE_SIZE = 50;
    private const MAX_SEARCH_LENGTH = 100;

    #[Route('', name: 'app_admin_audit_log', methods: ['GET'])]
    public function index(Request $request, AuditLogRepository $logs): Response
    {
        $queryParameters = $request->query->all();
        $rawQuery = $queryParameters['q'] ?? '';
        $query = is_string($rawQuery) ? mb_substr(trim($rawQuery), 0, self::MAX_SEARCH_LENGTH) : '';

        $rawPage = $queryParameters['page'] ?? null;
        $page = is_string($rawPage) && ctype_digit($rawPage) ? (int) $rawPage : 1;

        $total = $logs->countAdmin($query);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min(max(1, $page), $pages);

        return $this->render('admin/audit_log/index.html.twig', [
            'logs' => $logs->searchAdmin($query, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'query' => $query,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }
}

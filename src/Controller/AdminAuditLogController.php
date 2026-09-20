<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/audit-log')]
#[IsGranted('CMS_AUDIT_VIEW')]
final class AdminAuditLogController extends AbstractController
{
    #[Route('', name: 'app_admin_audit_log', methods: ['GET'])]
    public function index(AuditLogRepository $logs): Response
    {
        return $this->render('admin/audit_log/index.html.twig', ['logs' => $logs->findBy([], ['createdAt' => 'DESC'], 500)]);
    }
}

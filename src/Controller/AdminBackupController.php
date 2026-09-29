<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backup\BackupInventoryBrowser;
use App\Backup\LocalBackupInventory;
use App\Repository\BackupVerificationStatusRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/backups')]
#[IsGranted('CMS_CONNECTORS_MANAGE')]
final class AdminBackupController extends AbstractController
{
    #[Route('', name: 'app_admin_backup_index', methods: ['GET'])]
    public function index(
        Request $request,
        LocalBackupInventory $inventory,
        BackupVerificationStatusRepository $statuses,
        BackupInventoryBrowser $browser,
    ): Response {
        $query = $request->query->all();
        try {
            $criteria = $browser->normalizeRequest(
                $query['q'] ?? '',
                $query['status'] ?? 'all',
                $query['page'] ?? '1',
            );
        } catch (\InvalidArgumentException $exception) {
            throw new BadRequestHttpException('Invalid backup inventory query.', $exception);
        }

        $snapshot = $inventory->read();
        $verificationStatuses = $statuses->indexed();
        try {
            $page = $browser->paginate(
                $snapshot['backups'],
                $verificationStatuses,
                $criteria['query'],
                $criteria['verification_filter'],
                $criteria['page'],
            );
        } catch (\OutOfRangeException $exception) {
            throw new NotFoundHttpException('Backup inventory page does not exist.', $exception);
        }

        $latestBackup = $page['latest_backup'];

        $response = $this->render('admin/backups/index.html.twig', [
            'inventory_available' => $snapshot['available'],
            'inventory_total' => count($snapshot['backups']),
            'backups' => $page['backups'],
            'verification_statuses' => $verificationStatuses,
            'latest_backup' => $latestBackup,
            'latest_status' => $latestBackup === null ? null : ($verificationStatuses[$latestBackup['id']] ?? null),
            'query' => $criteria['query'],
            'verification_filter' => $criteria['verification_filter'],
            'current_page' => $page['current_page'],
            'page_size' => $page['page_size'],
            'total_backups' => $page['total_backups'],
            'total_pages' => $page['total_pages'],
            'first_result' => $page['first_result'],
            'last_result' => $page['last_result'],
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

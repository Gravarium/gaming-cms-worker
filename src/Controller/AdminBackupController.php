<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backup\LocalBackupInventory;
use App\Repository\BackupVerificationStatusRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/backups')]
#[IsGranted('CMS_CONNECTORS_MANAGE')]
final class AdminBackupController extends AbstractController
{
    #[Route('', name: 'app_admin_backup_index', methods: ['GET'])]
    public function index(
        LocalBackupInventory $inventory,
        BackupVerificationStatusRepository $statuses,
    ): Response {
        $snapshot = $inventory->read();

        return $this->render('admin/backups/index.html.twig', [
            'inventory_available' => $snapshot['available'],
            'backups' => array_slice($snapshot['backups'], 0, 30),
            'verification_statuses' => $statuses->indexed(),
        ]);
    }
}

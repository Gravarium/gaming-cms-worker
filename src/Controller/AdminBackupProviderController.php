<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backup\BackupProviderCatalog;
use App\Backup\BackupSelectionSynchronizer;
use App\Backup\BackupTargetPlanner;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/connectors/backup-catalog')]
#[IsGranted('CMS_CONNECTORS_MANAGE')]
final class AdminBackupProviderController extends AbstractController
{
    #[Route('', name: 'app_admin_backup_provider_catalog', methods: ['GET'])]
    public function index(BackupProviderCatalog $catalog): Response
    {
        return $this->render('admin/connectors/backup_catalog.html.twig', [
            'providers' => $catalog->all(),
        ]);
    }

    #[Route('/plan', name: 'app_admin_backup_provider_plan', methods: ['POST'])]
    public function plan(
        Request $request,
        BackupTargetPlanner $planner,
        BackupSelectionSynchronizer $selection,
        AuditLogger $audit,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid('backup-provider-plan', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $providers = $request->request->all('providers');
        $result = $planner->plan(array_values(array_filter($providers, 'is_string')));
        if ($result['created'] > 0) {
            $audit->record(
                'connector.backup_catalog.plan',
                self::class,
                null,
                'Backup-Ziele aus dem Anbieter-Katalog vorbereitet.',
                ['count' => $result['created'], 'targetKeys' => $result['keys']],
            );
            $entityManager->flush();
            $selection->synchronize();
        }

        $this->addFlash(
            'success',
            sprintf('%d Backup-Ziele vorbereitet, %d bereits vorhanden. Alle neuen Ziele bleiben deaktiviert.', $result['created'], $result['skipped']),
        );

        return $this->redirectToRoute('app_admin_connector_index');
    }
}

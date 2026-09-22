<?php

declare(strict_types=1);

namespace App\Controller;

use App\Backup\BackupPrivateConfigurationStatus;
use App\Backup\BackupSelectionSynchronizer;
use App\Entity\ExternalConnectorTarget;
use App\Form\ExternalConnectorTargetType;
use App\ExternalConnector\BackupTargetOverview;
use App\ExternalConnector\ExternalConnectorPrivateConfiguration;
use App\ExternalConnector\MediaTargetConfigurationStatus;
use App\ExternalConnector\OffsiteBackupStatusReader;
use App\Repository\ExternalConnectorHealthStatusRepository;
use App\Repository\ExternalConnectorTargetRepository;
use App\Repository\MediaReplicationTaskRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/connectors')]
#[IsGranted('CMS_CONNECTORS_MANAGE')]
final class AdminConnectorController extends AbstractController
{
    public function __construct(
        private readonly ExternalConnectorTargetRepository $targets,
        private readonly OffsiteBackupStatusReader $backupStatuses,
        private readonly BackupTargetOverview $backupOverview,
        private readonly BackupPrivateConfigurationStatus $backupConfigurationStatuses,
        private readonly BackupSelectionSynchronizer $backupSelection,
        private readonly ExternalConnectorPrivateConfiguration $connectorConfigurations,
        private readonly MediaTargetConfigurationStatus $mediaConfigurationStatuses,
        private readonly ExternalConnectorHealthStatusRepository $healthStatuses,
        private readonly MediaReplicationTaskRepository $mediaReplicationTasks,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: 'app_admin_connector_index', methods: ['GET'])]
    public function index(): Response
    {
        $targets = $this->targets->ordered();
        $backupStatuses = $this->backupStatuses->read();
        $connectorHealthStatuses = [];
        foreach ([
            ExternalConnectorTarget::CAPABILITY_MAIL,
            ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS,
            ExternalConnectorTarget::CAPABILITY_IDENTITY,
            ExternalConnectorTarget::CAPABILITY_CDN,
            ExternalConnectorTarget::CAPABILITY_ANALYTICS,
        ] as $capability) {
            $connectorHealthStatuses += $this->healthStatuses->forCapability($capability);
        }

        return $this->render('admin/connectors/index.html.twig', [
            'targets' => $targets,
            'backup_targets' => array_values(array_filter(
                $targets,
                static fn (ExternalConnectorTarget $target): bool => $target->getCapability() === ExternalConnectorTarget::CAPABILITY_BACKUP,
            )),
            'backup_statuses' => $backupStatuses,
            'backup_configuration_statuses' => $this->backupConfigurationStatuses->read(),
            'connector_configuration_statuses' => $this->connectorConfigurations->forTargets($targets),
            'connector_health_statuses' => $connectorHealthStatuses,
            'backup_overview' => $this->backupOverview->summarize($targets, $backupStatuses),
            'media_configuration_statuses' => $this->mediaConfigurationStatuses->forTargets($targets),
            'media_health_statuses' => $this->healthStatuses->forCapability(ExternalConnectorTarget::CAPABILITY_MEDIA),
            'media_repair_counts' => $this->mediaReplicationTasks->countsByTarget(),
        ]);
    }

    #[Route('/new', name: 'app_admin_connector_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        return $this->save(new ExternalConnectorTarget(), $request, true);
    }

    #[Route('/settings', name: 'app_admin_connector_settings', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('connector-settings', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $priorities = $request->request->all('priority');
        $required = $request->request->all('required');
        $changed = 0;

        foreach ($priorities as $id => $priority) {
            if (!is_scalar($priority) || preg_match('/^\d{1,5}$/', (string) $priority) !== 1) {
                continue;
            }
            $target = $this->targets->find((int) $id);
            $value = (int) $priority;
            if (!$target instanceof ExternalConnectorTarget || $value > 10000) {
                continue;
            }

            $target->setPriority($value)->setRequired(array_key_exists((string) $id, $required));
            ++$changed;
        }

        if ($changed > 0) {
            $this->audit->record(
                'connector.bulk_update',
                ExternalConnectorTarget::class,
                null,
                'Reihenfolge und Pflichtziele externer Ziele gemeinsam aktualisiert.',
                ['count' => $changed],
            );
            $this->entityManager->flush();
            $this->backupSelection->synchronize();
        }

        $this->addFlash('success', sprintf('%d externe Ziele gemeinsam aktualisiert.', $changed));

        return $this->redirectToRoute('app_admin_connector_index');
    }

    #[Route('/{id}/duplicate', name: 'app_admin_connector_duplicate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicate(ExternalConnectorTarget $target, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('duplicate-connector-'.$target->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $number = 2;
        do {
            $targetKey = $target->getTargetKey().'-'.$number;
            ++$number;
        } while ($this->targets->findOneBy([
            'capability' => $target->getCapability(),
            'targetKey' => $targetKey,
        ]) !== null);

        $copyNumber = $number - 1;
        $referenceBase = $target->getConfigurationReference() ?? $target->getCapability().'.'.$target->getProviderKey();
        $copy = (new ExternalConnectorTarget())
            ->setCapability($target->getCapability())
            ->setTargetKey($targetKey)
            ->setProviderKey($target->getProviderKey())
            ->setDisplayName($target->getDisplayName().' '.$copyNumber)
            ->setPriority(min(10000, $target->getPriority() + $copyNumber - 1))
            ->setRequired(false)
            ->setEnabled(false)
            ->setConfigurationReference($referenceBase.'.'.$copyNumber);

        $this->entityManager->persist($copy);
        $this->audit->record(
            'connector.duplicate',
            $copy,
            null,
            'Weiteres Anbieter-Konto vorbereitet.',
            ['sourceTargetKey' => $target->getTargetKey(), 'targetKey' => $targetKey],
        );
        $this->entityManager->flush();
        $this->synchronizeBackupSelection($copy);
        $this->addFlash('success', 'Ein weiteres Konto für denselben Anbieter wurde vorbereitet und bleibt deaktiviert.');

        return $this->redirectToRoute('app_admin_connector_index');
    }

    #[Route('/{id}/edit', name: 'app_admin_connector_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(ExternalConnectorTarget $target, Request $request): Response
    {
        return $this->save($target, $request, false);
    }

    #[Route('/{id}/toggle', name: 'app_admin_connector_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(ExternalConnectorTarget $target, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('toggle-connector-'.$target->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $enable = !$target->isEnabled();
        if ($enable && $target->getConfigurationReference() === null) {
            $this->addFlash('error', 'Das Ziel kann ohne Server-Konfigurationsverweis nicht aktiviert werden.');

            return $this->redirectToRoute('app_admin_connector_index');
        }

        $target->setEnabled($enable);
        $this->audit->record(
            'connector.toggle',
            $target,
            $target->getId(),
            $target->isEnabled() ? 'Externes Ziel aktiviert' : 'Externes Ziel deaktiviert',
            ['targetKey' => $target->getTargetKey(), 'capability' => $target->getCapability()],
        );
        $this->entityManager->flush();
        $this->synchronizeBackupSelection($target);
        $this->addFlash('success', $target->isEnabled() ? 'Das Ziel wurde aktiviert.' : 'Das Ziel wurde deaktiviert.');

        return $this->redirectToRoute('app_admin_connector_index');
    }

    #[Route('/{id}/delete', name: 'app_admin_connector_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(ExternalConnectorTarget $target, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete-connector-'.$target->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $isBackup = $target->getCapability() === ExternalConnectorTarget::CAPABILITY_BACKUP;
        $this->audit->record(
            'connector.delete',
            ExternalConnectorTarget::class,
            $target->getId(),
            'Externes Ziel aus der CMS-Verwaltung entfernt',
            ['targetKey' => $target->getTargetKey(), 'capability' => $target->getCapability()],
        );
        $this->entityManager->remove($target);
        $this->entityManager->flush();
        if ($isBackup) {
            $this->backupSelection->synchronize();
        }
        $this->addFlash('success', 'Die Zieldefinition wurde entfernt. Externe Daten und Server-Zugangsdaten wurden nicht gelöscht.');

        return $this->redirectToRoute('app_admin_connector_index');
    }

    private function save(ExternalConnectorTarget $target, Request $request, bool $new): Response
    {
        $form = $this->createForm(ExternalConnectorTargetType::class, $target)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($new) {
                $this->entityManager->persist($target);
            }
            $this->audit->record(
                $new ? 'connector.create' : 'connector.update',
                $target,
                $target->getId(),
                $new ? 'Externes Ziel angelegt' : 'Externes Ziel aktualisiert',
                ['targetKey' => $target->getTargetKey(), 'capability' => $target->getCapability(), 'providerKey' => $target->getProviderKey()],
            );
            $this->entityManager->flush();
            $this->synchronizeBackupSelection($target);
            $this->addFlash('success', 'Die Zieldefinition wurde gespeichert. Es wurden keine Zugangsdaten im CMS gespeichert.');

            return $this->redirectToRoute('app_admin_connector_index');
        }

        return $this->render('admin/connectors/form.html.twig', [
            'form' => $form,
            'target' => $target,
            'new' => $new,
        ]);
    }

    private function synchronizeBackupSelection(ExternalConnectorTarget $target): void
    {
        if ($target->getCapability() === ExternalConnectorTarget::CAPABILITY_BACKUP) {
            $this->backupSelection->synchronize();
        }
    }
}

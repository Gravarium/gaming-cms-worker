<?php

declare(strict_types=1);

namespace App\Controller;

use App\Messenger\FailedMessageOverview;
use App\Messenger\FailedMessageRecovery;
use App\Messenger\FailedMessageRetryUncertainException;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/queue')]
#[IsGranted('CMS_SETTINGS_MANAGE')]
final class AdminQueueController extends AbstractController
{
    public function __construct(
        private readonly FailedMessageOverview $overview,
        private readonly FailedMessageRecovery $recovery,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_admin_queue_index', methods: ['GET'])]
    public function index(): Response
    {
        $snapshot = $this->overview->read();

        return $this->render('admin/queue/index.html.twig', $snapshot);
    }

    #[Route('/{id}/retry', name: 'app_admin_queue_retry', requirements: ['id' => '[1-9][0-9]*'], methods: ['POST'])]
    public function retry(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('queue-retry-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $uncertain = false;
        try {
            $retried = $this->recovery->retry($id);
        } catch (FailedMessageRetryUncertainException) {
            $retried = false;
            $uncertain = true;
        } catch (\Throwable) {
            $retried = false;
        }

        if ($uncertain) {
            $this->audit->record(
                'queue.failed.retry_uncertain',
                self::class,
                null,
                'Queue-Retry ausgelöst, aber Entfernen aus der Fehlerqueue nicht bestätigt.',
                ['messageId' => $id],
            );
            $this->entityManager->flush();
            $this->addFlash('error', 'Die erneute Zustellung wurde ausgelöst, aber der Queue-Zustand ist unklar. Nicht blind erneut versuchen.');
        } elseif ($retried) {
            $this->audit->record('queue.failed.retry', self::class, null, 'Fehlgeschlagene Nachricht erneut eingeplant.', ['messageId' => $id]);
            $this->entityManager->flush();
            $this->addFlash('success', 'Die Nachricht wurde sicher erneut eingeplant.');
        } else {
            $this->addFlash('error', 'Die Nachricht konnte nicht erneut eingeplant werden oder wurde bereits verarbeitet.');
        }

        return $this->redirectToRoute('app_admin_queue_index');
    }

    #[Route('/retry-all', name: 'app_admin_queue_retry_all', methods: ['POST'])]
    public function retryAll(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('queue-retry-all', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $messages = $this->overview->read()['messages'];
        $successful = 0;
        $uncertain = 0;
        foreach ($messages as $message) {
            try {
                if ($this->recovery->retry($message['id'])) {
                    ++$successful;
                }
            } catch (FailedMessageRetryUncertainException) {
                ++$uncertain;
            } catch (\Throwable) {
                // Dispatch did not complete; the original failure remains available for a later retry.
            }
        }

        if ($successful > 0 || $uncertain > 0) {
            $this->audit->record(
                'queue.failed.retry_all',
                self::class,
                null,
                'Fehlgeschlagene Nachrichten erneut eingeplant.',
                ['confirmed' => $successful, 'uncertain' => $uncertain],
            );
            $this->entityManager->flush();
        }
        $this->addFlash(
            $successful === count($messages) && $uncertain === 0 ? 'success' : 'error',
            sprintf(
                '%d von %d Nachrichten bestätigt erneut eingeplant; %d weitere haben einen unklaren Dispatch/Ack-Zustand.',
                $successful,
                count($messages),
                $uncertain,
            ),
        );

        return $this->redirectToRoute('app_admin_queue_index');
    }
}

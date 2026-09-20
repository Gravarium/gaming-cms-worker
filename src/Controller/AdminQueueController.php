<?php

declare(strict_types=1);

namespace App\Controller;

use App\Messenger\FailedMessageOverview;
use App\Messenger\FailedMessageRecovery;
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

        try {
            $retried = $this->recovery->retry($id);
        } catch (\Throwable) {
            $retried = false;
        }

        if ($retried) {
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
        foreach ($messages as $message) {
            try {
                if ($this->recovery->retry($message['id'])) {
                    ++$successful;
                }
            } catch (\Throwable) {
                // Leave the individual message in the failure transport.
            }
        }

        if ($successful > 0) {
            $this->audit->record('queue.failed.retry_all', self::class, null, 'Fehlgeschlagene Nachrichten erneut eingeplant.', ['count' => $successful]);
            $this->entityManager->flush();
        }
        $this->addFlash(
            $successful === count($messages) ? 'success' : 'error',
            sprintf('%d von %d Nachrichten wurden erneut eingeplant.', $successful, count($messages)),
        );

        return $this->redirectToRoute('app_admin_queue_index');
    }
}

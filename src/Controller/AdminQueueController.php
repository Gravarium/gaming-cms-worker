<?php

declare(strict_types=1);

namespace App\Controller;

use App\Messenger\FailedMessagePaginator;
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
        private readonly FailedMessagePaginator $paginator,
        private readonly FailedMessageRecovery $recovery,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_admin_queue_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $snapshot = $this->pageSnapshot($request);
        if ($snapshot instanceof Response) {
            return $snapshot;
        }

        return $this->render('admin/queue/index.html.twig', $snapshot);
    }

    #[Route('/{id}/retry', name: 'app_admin_queue_retry', requirements: ['id' => '[1-9][0-9]*'], methods: ['POST'])]
    public function retry(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('queue-retry-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $snapshot = $this->pageSnapshot($request);
        if ($snapshot instanceof Response) {
            return $snapshot;
        }
        $page = $snapshot['page'];

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

        return $this->redirectToRoute('app_admin_queue_index', ['page' => $page]);
    }

    #[Route('/retry-all', name: 'app_admin_queue_retry_all', methods: ['POST'])]
    public function retryAll(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('queue-retry-all', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $snapshot = $this->pageSnapshot($request);
        if ($snapshot instanceof Response) {
            return $snapshot;
        }
        $messages = $snapshot['messages'];
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
            $messages !== [] && $successful === count($messages) ? 'success' : 'error',
            sprintf('%d von %d Nachrichten wurden erneut eingeplant.', $successful, count($messages)),
        );

        return $this->redirectToRoute('app_admin_queue_index', ['page' => $snapshot['page']]);
    }

    /**
     * @return array{
     *     total: int,
     *     messages: list<array{id: string, type: string, createdAt: \DateTimeImmutable, bytes: int}>,
     *     page: int,
     *     pageCount: int,
     *     first: int,
     *     last: int
     * }|Response
     */
    private function pageSnapshot(Request $request): array|Response
    {
        $query = $request->query->all();
        $pageInput = $query['page'] ?? null;

        try {
            return $this->paginator->read($pageInput);
        } catch (\InvalidArgumentException) {
            return new Response(
                'Die Seitennummer ist ungültig.',
                Response::HTTP_BAD_REQUEST,
                ['Cache-Control' => 'no-store', 'Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }
    }
}

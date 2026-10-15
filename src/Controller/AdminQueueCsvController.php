<?php

declare(strict_types=1);

namespace App\Controller;

use App\Messenger\FailedMessageCsvExporter;
use App\Messenger\FailedMessagePaginator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/queue', name: 'app_admin_queue_')]
#[IsGranted('CMS_SETTINGS_MANAGE')]
final class AdminQueueCsvController extends AbstractController
{
    public function __construct(
        private readonly FailedMessagePaginator $paginator,
        private readonly FailedMessageCsvExporter $exporter,
    ) {
    }

    #[Route('/export.csv', name: 'export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $parameters = $request->query->all();
        if (array_diff(array_keys($parameters), ['page']) !== []) {
            return $this->badRequest();
        }

        try {
            $snapshot = $this->paginator->read($parameters['page'] ?? null);
        } catch (\InvalidArgumentException) {
            return $this->badRequest();
        }

        $response = new Response($this->exporter->export($snapshot['messages']));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('failed-message-queue-page-%d.csv', $snapshot['page']),
        ));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function badRequest(): Response
    {
        return new Response(
            'Die Seitennummer ist ungültig.',
            Response::HTTP_BAD_REQUEST,
            [
                'Cache-Control' => 'private, no-store',
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Robots-Tag' => 'noindex',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\NewsletterDeliveryReport\NewsletterDeliveryReportQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/newsletter/delivery-report', name: 'app_admin_notification_newsletter_delivery_report')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminNewsletterDeliveryReportController extends AbstractController
{
    #[Route('', methods: ['GET'])]
    public function __invoke(Request $request, NewsletterDeliveryReportQuery $report): Response
    {
        $parameters = $request->query->all();
        if (array_diff(array_keys($parameters), ['after']) !== []) {
            return $this->privateResponse(new Response('Ungültige Seitennavigation.', Response::HTTP_BAD_REQUEST));
        }

        $cursor = $parameters['after'] ?? null;
        if ($cursor !== null && !is_string($cursor)) {
            return $this->privateResponse(new Response('Ungültige Seitennavigation.', Response::HTTP_BAD_REQUEST));
        }

        try {
            $listing = $report->recentCampaigns($cursor);
        } catch (\InvalidArgumentException) {
            return $this->privateResponse(new Response('Ungültige Seitennavigation.', Response::HTTP_BAD_REQUEST));
        }

        $response = $this->render('@NewsletterDeliveryReport/index.html.twig', $listing + [
            'hasCursor' => $cursor !== null,
        ]);

        return $this->privateResponse($response);
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}

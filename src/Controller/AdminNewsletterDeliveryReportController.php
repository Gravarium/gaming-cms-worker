<?php

declare(strict_types=1);

namespace App\Controller;

use App\NewsletterDeliveryReport\NewsletterDeliveryReportQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/newsletter/delivery-report', name: 'app_admin_notification_newsletter_delivery_report')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminNewsletterDeliveryReportController extends AbstractController
{
    #[Route('', methods: ['GET'])]
    public function __invoke(NewsletterDeliveryReportQuery $report): Response
    {
        $response = $this->render('@NewsletterDeliveryReport/index.html.twig', $report->recentCampaigns());
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}

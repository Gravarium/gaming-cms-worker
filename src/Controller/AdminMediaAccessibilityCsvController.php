<?php

declare(strict_types=1);

namespace App\Controller;

use App\MediaAccessibility\MediaAccessibilityReport;
use App\MediaAccessibility\MediaAccessibilityReportCsv;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/storage/accessibility/export.csv')]
#[IsGranted('CMS_STORAGE_MANAGE')]
final class AdminMediaAccessibilityCsvController extends AbstractController
{
    public function __construct(
        private readonly MediaAccessibilityReport $report,
        private readonly MediaAccessibilityReportCsv $csv,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('', name: 'app_admin_storage_accessibility_export', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->modules->isEnabled('media')) {
            throw new NotFoundHttpException();
        }

        $export = $this->report->export();
        $response = new Response($this->csv->encode($export['assets']));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="media-accessibility-report.csv"');
        $response->headers->set('X-Accessibility-Report-Truncated', $export['truncated'] ? 'true' : 'false');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}

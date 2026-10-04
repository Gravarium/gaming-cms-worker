<?php

declare(strict_types=1);

namespace App\Controller;

use App\Accessibility\Content\ContentAccessibilityReport;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/content-accessibility', name: 'app_admin_content_accessibility', methods: ['GET'])]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminContentAccessibilityController extends AbstractController
{
    public function __invoke(
        Request $request,
        CmsModuleManager $modules,
        ContentAccessibilityReport $report,
    ): Response {
        if (!$modules->isEnabled('content')) {
            throw $this->createNotFoundException();
        }

        try {
            $data = $report->build($request->query->all());
        } catch (\InvalidArgumentException) {
            return $this->privateResponse(new Response('Ungültige Filterparameter.', Response::HTTP_BAD_REQUEST));
        }

        return $this->privateResponse($this->render('admin/content_accessibility/index.html.twig', [
            'report' => $data,
        ]));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}

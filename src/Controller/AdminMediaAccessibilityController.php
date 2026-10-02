<?php

declare(strict_types=1);

namespace App\Controller;

use App\MediaAccessibility\MediaAccessibilityReport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/storage/accessibility', name: 'app_admin_storage_accessibility_')]
#[IsGranted('CMS_STORAGE_MANAGE')]
final class AdminMediaAccessibilityController extends AbstractController
{
    public function __construct(private readonly MediaAccessibilityReport $report)
    {
    }

    #[Route('', name: 'report', methods: ['GET'])]
    public function report(Request $request): Response
    {
        try {
            $page = $this->requestedPage($request);
            $result = $this->report->page($page);
        } catch (\InvalidArgumentException) {
            return $this->privateResponse(new Response('Ungültige Seitenzahl.', Response::HTTP_BAD_REQUEST));
        } catch (\OutOfRangeException) {
            return $this->privateResponse(new Response('Diese Berichtsseite existiert nicht.', Response::HTTP_NOT_FOUND));
        }

        return $this->privateResponse($this->render('@media_accessibility/admin/index.html.twig', $result));
    }

    private function requestedPage(Request $request): int
    {
        $query = $request->query->all();
        $rawPage = $query['page'] ?? null;
        if ($rawPage === null) {
            return 1;
        }

        if (!is_string($rawPage) || strlen($rawPage) > 5 || !ctype_digit($rawPage)) {
            throw new \InvalidArgumentException('Page must be a bounded positive integer.');
        }

        $page = (int) $rawPage;
        if ($page < 1 || $page > MediaAccessibilityReport::MAX_PAGE) {
            throw new \InvalidArgumentException('Page must be a bounded positive integer.');
        }

        return $page;
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}

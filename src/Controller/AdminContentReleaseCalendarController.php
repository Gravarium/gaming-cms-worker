<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentRelease\AdminContentReleaseCalendar;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/content/releases/calendar', name: 'app_admin_content_release_calendar', methods: ['GET'])]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminContentReleaseCalendarController extends AbstractController
{
    public function __construct(private readonly AdminContentReleaseCalendar $calendar)
    {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $data = $this->calendar->read($request->query->all()['month'] ?? null);
        } catch (\InvalidArgumentException) {
            return new Response('Ungültiger Monatsparameter.', Response::HTTP_BAD_REQUEST, [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        return $this->render('admin/content_release/calendar.html.twig', $data);
    }
}

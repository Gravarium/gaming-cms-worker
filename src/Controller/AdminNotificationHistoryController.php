<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AdminNotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('CMS_ACCESS')]
final class AdminNotificationHistoryController extends AbstractController
{
    private const PAGE_SIZE = 25;
    private const MAX_PAGE = 10000;
    private const STATES = ['all', 'unread', 'read'];

    public function __construct(private readonly AdminNotificationRepository $notifications)
    {
    }

    #[Route('/admin/notifications/history', name: 'app_admin_notification_history', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $parameters = $request->query->all();
        $pageValue = $parameters['page'] ?? null;
        if ($pageValue === null) {
            $page = 1;
        } elseif (!is_string($pageValue) || !ctype_digit($pageValue) || strlen($pageValue) > 5) {
            throw $this->createNotFoundException();
        } else {
            $page = (int) $pageValue;
            if ($page < 1 || $page > self::MAX_PAGE) {
                throw $this->createNotFoundException();
            }
        }

        $queryValue = $parameters['q'] ?? '';
        if (!is_string($queryValue)) {
            throw $this->createNotFoundException();
        }
        $query = mb_substr(trim($queryValue), 0, 100);

        $stateValue = $parameters['state'] ?? 'all';
        if (!is_string($stateValue) || !in_array($stateValue, self::STATES, true)) {
            throw $this->createNotFoundException();
        }
        $state = $stateValue;

        $total = $this->notifications->countHistory($query, $state);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $pages) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('admin/notification/history.html.twig', [
            'notifications' => $this->notifications->searchHistory($query, $state, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'query' => $query,
            'state' => $state,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

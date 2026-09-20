<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AdminNotification;
use App\Repository\AdminNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/notifications')]
#[IsGranted('CMS_ACCESS')]
final class AdminNotificationController extends AbstractController
{
    public function __construct(private readonly AdminNotificationRepository $notifications, private readonly EntityManagerInterface $entityManager) {}

    #[Route('', name: 'app_admin_notification_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/notification/index.html.twig', ['notifications' => $this->notifications->findBy([], ['createdAt' => 'DESC'], 100)]);
    }

    #[Route('/{id}/read', name: 'app_admin_notification_read', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function read(AdminNotification $notification, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('read-notification-'.$notification->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $notification->markRead();
        $this->entityManager->flush();
        return $notification->getLink() ? $this->redirect($notification->getLink()) : $this->redirectToRoute('app_admin_notification_index');
    }

    #[Route('/read-all', name: 'app_admin_notification_read_all', methods: ['POST'])]
    public function readAll(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('read-all-notifications', (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        foreach ($this->notifications->findBy(['readAt' => null]) as $notification) { $notification->markRead(); }
        $this->entityManager->flush();
        return $this->redirectToRoute('app_admin_notification_index');
    }
}

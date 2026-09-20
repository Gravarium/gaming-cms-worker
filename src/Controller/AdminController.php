<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AdminNotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('CMS_ACCESS')]
final class AdminController extends AbstractController
{
    #[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
    public function dashboard(AdminNotificationRepository $notifications): Response
    {
        return $this->render('admin/dashboard.html.twig', ['unreadNotifications' => $notifications->unreadCount()]);
    }
}

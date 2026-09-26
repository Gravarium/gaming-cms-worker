<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MemberNotification;
use App\Entity\User;
use App\Repository\MemberNotificationRepository;
use App\Security\LocalRedirectTarget;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/guild-area/notifications')]
#[IsGranted('ROLE_USER')]
final class MemberNotificationInboxController extends AbstractController
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly MemberNotificationRepository $notifications,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: 'app_member_notification_inbox', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        $page = $this->requestedPage($request);
        $total = $this->notifications->countForUser($user);
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));

        if ($page > $pageCount) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('guild_portal/notifications.html.twig', [
            'notifications' => $this->notifications->pageForUser($user, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'unreadNotifications' => $this->notifications->unreadCount($user),
            'totalNotifications' => $total,
            'page' => $page,
            'pageCount' => $pageCount,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }

    #[Route('/{id}/read', name: 'app_member_notification_inbox_read', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function read(int $id, Request $request): Response
    {
        $user = $this->currentUser();
        if (!$this->isCsrfTokenValid('member-notification-inbox-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $notification = $this->notifications->findOneBy(['id' => $id, 'user' => $user]);
        if (!$notification instanceof MemberNotification) {
            throw $this->createNotFoundException();
        }

        $notification->markRead();
        $this->entityManager->flush();

        $fallback = $this->generateUrl('app_member_notification_inbox');
        $target = LocalRedirectTarget::normalize($notification->getLink(), $fallback);

        return $this->redirect($target ?? $fallback);
    }

    #[Route('/mark-all-read', name: 'app_member_notification_inbox_read_all', methods: ['POST'])]
    public function readAll(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('member-notification-inbox-read-all', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        foreach ($this->notifications->unreadForUser($this->currentUser()) as $notification) {
            $notification->markRead();
        }
        $this->entityManager->flush();

        return $this->redirectToRoute('app_member_notification_inbox');
    }

    private function requestedPage(Request $request): int
    {
        $query = $request->query->all();
        if (!array_key_exists('page', $query)) {
            return 1;
        }

        $value = $query['page'];
        if (!is_string($value) || preg_match('/\\A[1-9][0-9]{0,8}\\z/', $value) !== 1) {
            throw $this->createNotFoundException();
        }

        return (int) $value;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}

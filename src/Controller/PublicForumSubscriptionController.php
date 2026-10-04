<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\ForumSubscription\VisibleSubscriptions;
use App\ForumWorkflow\ForumWorkflowGateway;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicForumSubscriptionController extends AbstractController
{
    public function __construct(
        private readonly VisibleSubscriptions $subscriptions,
        private readonly ForumWorkflowGateway $forum,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/forum/subscriptions', name: 'app_gaming_forum_subscriptions', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted('ROLE_USER');
        $user = $this->getUser();
        if (!$user instanceof User || $user->getId() === null) {
            throw $this->createAccessDeniedException();
        }

        $raw = $request->query->all()['page'] ?? '1';
        if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,3}$/', $raw) !== 1
            || (int) $raw > VisibleSubscriptions::MAX_PAGE) {
            throw $this->createNotFoundException();
        }
        $page = (int) $raw;
        $result = $this->subscriptions->page(
            $user->getId(),
            $this->forum->guildIdsForUser($user->getId()),
            $this->isGranted(CmsPermission::GAMING),
            $page,
        );
        $pages = max(1, min(VisibleSubscriptions::MAX_PAGE, (int) ceil($result['total'] / VisibleSubscriptions::PAGE_SIZE)));
        if ($page > $pages) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('@forum_workflow/public/subscriptions.html.twig', [
            'threads' => $result['threads'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => $pages,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}

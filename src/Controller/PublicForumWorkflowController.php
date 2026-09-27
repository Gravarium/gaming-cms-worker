<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\ForumWorkflow\ForumWorkflowGateway;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicForumWorkflowController extends AbstractController
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private readonly ForumWorkflowGateway $forum,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/forum', name: 'app_gaming_forum_index', methods: ['GET'])]
    public function rooms(Request $request): Response
    {
        $this->requireGaming();
        [$userId, $guildIds, $isModerator] = $this->viewerContext();
        $page = $this->pageNumber($request);
        $total = $this->forum->visibleRoomCount($userId, $guildIds, $isModerator, true);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $pages) {
            throw $this->createNotFoundException();
        }

        return $this->render('@forum_workflow/public/rooms.html.twig', [
            'rooms' => $this->forum->visibleRooms($userId, $guildIds, $isModerator, true, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    #[Route('/forum/rooms/{id}', name: 'app_gaming_forum_room', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function room(int $id, Request $request): Response
    {
        $this->requireGaming();
        [$userId, $guildIds, $isModerator] = $this->viewerContext();
        $room = $this->forum->visibleRoom($id, $userId, $guildIds, $isModerator, true);
        if ($room === null) {
            throw $this->createNotFoundException();
        }

        $page = $this->pageNumber($request);
        $total = $this->forum->threadCountForRoom($id);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $pages) {
            throw $this->createNotFoundException();
        }

        return $this->render('@forum_workflow/public/threads.html.twig', [
            'room' => $room,
            'threads' => $this->forum->threadsForRoom($id, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    #[Route('/forum/rooms/{id}/threads', name: 'app_gaming_forum_thread_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function createThread(int $id, Request $request): Response
    {
        $this->requireGaming();
        $authorId = $this->requireUserId();
        [$userId, $guildIds, $isModerator] = $this->viewerContext();
        $room = $this->forum->visibleRoom($id, $userId, $guildIds, $isModerator, true);
        if ($room === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('forum_thread_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $threadId = $this->forum->createThread(
                $id,
                $authorId,
                $request->request->getString('title'),
                $request->request->getString('body'),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('forum_error', $exception->getMessage());

            return $this->redirectToRoute('app_gaming_forum_room', ['id' => $id]);
        }

        return $this->redirectToRoute('app_gaming_forum_thread', ['id' => $threadId]);
    }

    #[Route('/forum/threads/{id}', name: 'app_gaming_forum_thread', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function thread(int $id): Response
    {
        $this->requireGaming();
        [$userId, $guildIds, $isModerator] = $this->viewerContext();
        $thread = $this->forum->visibleThread($id, $userId, $guildIds, $isModerator, true);
        if ($thread === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('@forum_workflow/public/thread.html.twig', [
            'thread' => $thread,
            'posts' => $this->forum->postsForThread($id),
            'canReply' => $thread['state'] === 'open' && $thread['solved_post_id'] === null,
            'canSolve' => $userId !== null && (int) $thread['author_id'] === $userId
                && $thread['state'] === 'open' && $thread['solved_post_id'] === null,
            'isModerator' => $isModerator,
            'isSubscribed' => $userId !== null && $this->forum->isSubscribed($id, $userId),
        ]);
    }

    #[Route('/forum/threads/{id}/replies', name: 'app_gaming_forum_reply', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reply(int $id, Request $request): Response
    {
        $this->requireGaming();
        $authorId = $this->requireUserId();
        $this->visibleThreadOr404($id);
        if (!$this->isCsrfTokenValid('forum_reply_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $quoteRaw = $request->request->getString('quoted_post_id');
        $quotedPostId = null;
        if ($quoteRaw !== '') {
            $parsedQuote = filter_var($quoteRaw, FILTER_VALIDATE_INT);
            if ($parsedQuote === false || $parsedQuote < 1) {
                $this->addFlash('forum_error', 'Der zitierte Beitrag ist ungültig.');

                return $this->redirectToRoute('app_gaming_forum_thread', ['id' => $id]);
            }
            $quotedPostId = $parsedQuote;
        }

        try {
            $this->forum->reply(
                $id,
                $authorId,
                $request->request->getString('body'),
                $quotedPostId,
                $request->request->getInt('version', -1),
            );
        } catch (\InvalidArgumentException|\DomainException $exception) {
            $this->addFlash('forum_error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_gaming_forum_thread', ['id' => $id]);
    }

    #[Route('/forum/threads/{id}/solved-answer', name: 'app_gaming_forum_solve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function solve(int $id, Request $request): Response
    {
        $this->requireGaming();
        $authorId = $this->requireUserId();
        $this->visibleThreadOr404($id);
        if (!$this->isCsrfTokenValid('forum_solve_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $postId = filter_var($request->request->getString('post_id'), FILTER_VALIDATE_INT);
        if ($postId === false || $postId < 1 || !$this->forum->markSolved(
            $id,
            $postId,
            $authorId,
            $request->request->getInt('version', -1),
        )) {
            $this->addFlash('forum_error', 'Die Antwort konnte nicht als Lösung markiert werden. Prüfe, ob du das Thema erstellt hast, und lade es neu.');
        } else {
            $this->addFlash('forum_success', 'Die Antwort wurde als Lösung markiert.');
        }

        return $this->redirectToRoute('app_gaming_forum_thread', ['id' => $id]);
    }

    #[Route('/forum/threads/{id}/subscription', name: 'app_gaming_forum_subscription', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function subscription(int $id, Request $request): Response
    {
        $this->requireGaming();
        $userId = $this->requireUserId();
        $this->visibleThreadOr404($id);
        if (!$this->isCsrfTokenValid('forum_subscription_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $action = $request->request->getString('action');
        if (!in_array($action, ['subscribe', 'unsubscribe'], true)) {
            $this->addFlash('forum_error', 'Die Benachrichtigungseinstellung ist ungültig.');
        } else {
            $this->forum->setSubscription($id, $userId, $action === 'subscribe');
            $this->addFlash('forum_success', $action === 'subscribe' ? 'Du folgst jetzt diesem Thema.' : 'Du folgst diesem Thema nicht mehr.');
        }

        return $this->redirectToRoute('app_gaming_forum_thread', ['id' => $id]);
    }

    /** @return array{0: int|null, 1: list<int>, 2: bool} */
    private function viewerContext(): array
    {
        $user = $this->getUser();
        $userId = $user instanceof User ? $user->getId() : null;
        $guildIds = $userId === null ? [] : $this->forum->guildIdsForUser($userId);

        return [$userId, $guildIds, $this->isGranted(CmsPermission::GAMING)];
    }

    /** @return array<string, mixed> */
    private function visibleThreadOr404(int $id): array
    {
        [$userId, $guildIds, $isModerator] = $this->viewerContext();
        $thread = $this->forum->visibleThread($id, $userId, $guildIds, $isModerator, true);
        if ($thread === null) {
            throw $this->createNotFoundException();
        }

        return $thread;
    }

    private function requireUserId(): int
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $user = $this->getUser();
        if (!$user instanceof User || $user->getId() === null) {
            throw $this->createAccessDeniedException();
        }

        return $user->getId();
    }

    private function requireGaming(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }

    private function pageNumber(Request $request): int
    {
        $value = $request->query->getString('page', '1');
        if ($value === '' || !ctype_digit($value) || (int) $value < 1 || (int) $value > 1000) {
            throw $this->createNotFoundException();
        }

        return (int) $value;
    }
}

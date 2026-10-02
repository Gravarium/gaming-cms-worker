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

final class PublicForumEditController extends AbstractController
{
    public function __construct(
        private readonly ForumWorkflowGateway $forum,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route(
        '/forum/threads/{threadId}/posts/{postId}/edit',
        name: 'app_gaming_forum_post_edit',
        requirements: ['threadId' => '\\d+', 'postId' => '\\d+'],
        methods: ['GET'],
    )]
    public function edit(int $threadId, int $postId): Response
    {
        $this->requireGaming();
        $userId = $this->requireUserId();
        $thread = $this->visibleThreadOr404($threadId);
        $post = $this->forum->editablePost($threadId, $postId, $userId);
        if ($post === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('@forum_workflow/public/edit.html.twig', [
            'thread' => $thread,
            'post' => $post,
            'isQuestion' => (int) $post['is_question'] === 1,
        ]);
    }

    #[Route(
        '/forum/threads/{threadId}/posts/{postId}/edit',
        name: 'app_gaming_forum_post_update',
        requirements: ['threadId' => '\\d+', 'postId' => '\\d+'],
        methods: ['POST'],
    )]
    public function update(int $threadId, int $postId, Request $request): Response
    {
        $this->requireGaming();
        $userId = $this->requireUserId();
        $this->visibleThreadOr404($threadId);
        $post = $this->forum->editablePost($threadId, $postId, $userId);
        if ($post === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('forum_edit_'.$threadId.'_'.$postId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->forum->editPost(
                $threadId,
                $postId,
                $userId,
                $request->request->has('title') ? $request->request->getString('title') : null,
                $request->request->getString('body'),
                $request->request->getInt('version', -1),
            );
            $this->addFlash('forum_success', 'Der Beitrag wurde aktualisiert.');
        } catch (\InvalidArgumentException|\DomainException $exception) {
            $this->addFlash('forum_error', $exception->getMessage());

            return $this->redirectToRoute('app_gaming_forum_post_edit', ['threadId' => $threadId, 'postId' => $postId]);
        }

        return $this->redirectToRoute('app_gaming_forum_thread', ['id' => $threadId], Response::HTTP_SEE_OTHER);
    }

    /** @return array<string, mixed> */
    private function visibleThreadOr404(int $threadId): array
    {
        $user = $this->getUser();
        $userId = $user instanceof User ? $user->getId() : null;
        $guildIds = $userId === null ? [] : $this->forum->guildIdsForUser($userId);
        $thread = $this->forum->visibleThread(
            $threadId,
            $userId,
            $guildIds,
            $this->isGranted(CmsPermission::GAMING),
            true,
        );
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
}

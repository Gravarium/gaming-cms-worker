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
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/forum')]
#[IsGranted(CmsPermission::GAMING)]
final class AdminForumWorkflowController extends AbstractController
{
    public function __construct(
        private readonly ForumWorkflowGateway $forum,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/rooms', name: 'app_admin_gaming_forum_rooms', methods: ['GET'])]
    public function rooms(): Response
    {
        $this->requireGaming();

        return $this->render('@forum_workflow/admin/rooms.html.twig', ['rooms' => $this->forum->adminRooms()]);
    }

    #[Route('/rooms/new', name: 'app_admin_gaming_forum_room_new', methods: ['GET', 'POST'])]
    public function newRoom(Request $request): Response
    {
        $this->requireGaming();

        return $this->roomForm($request, null, 'forum_room_new');
    }

    #[Route('/rooms/{id}/edit', name: 'app_admin_gaming_forum_room_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editRoom(int $id, Request $request): Response
    {
        $this->requireGaming();
        $room = $this->forum->adminRoom($id);
        if ($room === null) {
            throw $this->createNotFoundException();
        }

        return $this->roomForm($request, $room, 'forum_room_edit_'.$id);
    }

    #[Route('/threads/{id}/moderate', name: 'app_admin_gaming_forum_moderate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moderate(int $id, Request $request): Response
    {
        $this->requireGaming();
        if (!$this->isCsrfTokenValid('forum_moderate_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $actor = $this->getUser();
        if (!$actor instanceof User || $actor->getId() === null) {
            throw $this->createAccessDeniedException();
        }
        $thread = $this->forum->visibleThread($id, $actor->getId(), $this->forum->guildIdsForUser($actor->getId()), true, true);
        if ($thread === null) {
            throw $this->createNotFoundException();
        }

        try {
            $updated = $this->forum->moderateThread(
                $id,
                $actor->getId(),
                $request->request->getString('state'),
                $request->request->getString('reason'),
                $request->request->getInt('version', -1),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('forum_error', $exception->getMessage());

            return $this->redirectToRoute('app_gaming_forum_thread', ['id' => $id]);
        }

        $this->addFlash(
            $updated ? 'forum_success' : 'forum_error',
            $updated ? 'Der Themenstatus wurde geändert und protokolliert.' : 'Das Thema wurde inzwischen geändert. Lade es neu und prüfe den aktuellen Status.',
        );

        return $this->redirectToRoute('app_gaming_forum_thread', ['id' => $id]);
    }

    /**
     * @param array<string, mixed>|null $room
     */
    private function roomForm(Request $request, ?array $room, string $tokenId): Response
    {
        $title = $request->isMethod('POST')
            ? $request->request->getString('title')
            : (string) ($room['title'] ?? '');
        $visibility = $request->isMethod('POST')
            ? $request->request->getString('visibility', 'public')
            : (string) ($room['visibility'] ?? 'public');
        $rawGuildId = $request->isMethod('POST')
            ? $request->request->getString('guild_id')
            : (string) ($room['guild_id'] ?? '');
        $guildId = null;
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            if ($rawGuildId !== '') {
                $parsed = filter_var($rawGuildId, FILTER_VALIDATE_INT);
                if ($parsed === false || $parsed < 1) {
                    $error = 'Wähle eine gültige Gilde aus.';
                } else {
                    $guildId = $parsed;
                }
            }
            if ($error === null) {
                try {
                    $roomId = $this->forum->saveRoom($room === null ? null : (int) $room['id'], $title, $visibility, $guildId);
                    $this->addFlash('forum_success', 'Der Forumraum wurde gespeichert.');

                    return $this->redirectToRoute('app_admin_gaming_forum_rooms', ['saved' => $roomId]);
                } catch (\InvalidArgumentException|\DomainException $exception) {
                    $error = $exception->getMessage();
                }
            }
        } elseif ($rawGuildId !== '') {
            $guildId = (int) $rawGuildId;
        }

        return $this->render(
            '@forum_workflow/admin/room_form.html.twig',
            [
                'room' => $room,
                'title' => $title,
                'visibility' => $visibility,
                'selectedGuildId' => $guildId,
                'guilds' => $this->forum->guildOptions(),
                'tokenId' => $tokenId,
                'error' => $error,
            ],
            new Response('', $error === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY),
        );
    }

    private function requireGaming(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }
}

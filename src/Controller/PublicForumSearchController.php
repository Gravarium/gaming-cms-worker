<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\ForumSearch\RoomThreadSearch;
use App\ForumWorkflow\ForumWorkflowGateway;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicForumSearchController extends AbstractController
{
    public function __construct(
        private readonly ForumWorkflowGateway $forum,
        private readonly RoomThreadSearch $search,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/forum/rooms/{id}/search', name: 'app_gaming_forum_room_search', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function search(int $id, Request $request): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
        $user = $this->getUser();
        $userId = $user instanceof User ? $user->getId() : null;
        $room = $this->forum->visibleRoom(
            $id,
            $userId,
            $userId === null ? [] : $this->forum->guildIdsForUser($userId),
            $this->isGranted(CmsPermission::GAMING),
            true,
        );
        if ($room === null) {
            throw $this->createNotFoundException();
        }

        $raw = $request->query->all();
        $term = $raw['q'] ?? null;
        $page = $raw['page'] ?? '1';
        if (!is_string($term) || strlen($term) > 400 || !mb_check_encoding($term, 'UTF-8')
            || !is_string($page) || preg_match('/^[1-9][0-9]{0,3}$/', $page) !== 1
            || (int) $page > RoomThreadSearch::MAX_PAGE) {
            throw $this->createNotFoundException();
        }
        $term = trim($term);
        if (mb_strlen($term) < 2 || mb_strlen($term) > 100) {
            throw $this->createNotFoundException();
        }
        $currentPage = (int) $page;
        $result = $this->search->search($id, $term, $currentPage);
        $pages = max(1, min(RoomThreadSearch::MAX_PAGE, intdiv($result['total'], RoomThreadSearch::PAGE_SIZE)
            + ($result['total'] % RoomThreadSearch::PAGE_SIZE === 0 ? 0 : 1)));
        if ($currentPage > $pages) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('@forum_workflow/public/search.html.twig', [
            'room' => $room,
            'term' => $term,
            'threads' => $result['threads'],
            'total' => $result['total'],
            'page' => $currentPage,
            'pages' => $pages,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}

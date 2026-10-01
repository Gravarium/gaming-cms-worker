<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Video\Discovery\VideoModuleAvailability;
use App\VideoWorkspace\OwnedCollectionGateway;
use App\VideoWorkspace\WorkspaceInput;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/account/video-workspace')]
#[IsGranted('ROLE_USER')]
final class VideoWorkspaceAccountController extends AbstractController
{
    public function __construct(private readonly OwnedCollectionGateway $collections, private readonly VideoModuleAvailability $module) {}
    #[Route('', name: 'app_video_workspace_account', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $id = $this->owner();
        try { $page = WorkspaceInput::page($request); } catch (\InvalidArgumentException $e) { throw new UnprocessableEntityHttpException($e->getMessage(), $e); }
        $lists = [];
        foreach (['watchlist', 'comment', 'clip'] as $kind) { $lists[$kind] = $this->collections->listing($kind, $id, $page); }
        return $this->privateResponse($this->render('video_workspace/account.html.twig', compact('lists', 'page')));
    }
    #[Route('/{kind}/{id}', name: 'app_video_workspace_owned', requirements: ['kind' => 'watchlist|comment|clip', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function item(string $kind, int $id, Request $request): Response
    {
        $owner = $this->owner();
        $row = $this->collections->item($kind, $id, $owner);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('video-workspace-'.$kind.'-'.$id, $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
            try {
                $action = $request->request->getString('action', 'edit');
                $changes = [];
                if ($action === 'edit') {
                    $changes = match ($kind) {
                        'watchlist' => ['name' => WorkspaceInput::text($request, 'name', 120), 'public' => WorkspaceInput::flag($request, 'public')],
                        'comment' => ['body' => WorkspaceInput::text($request, 'body', 4000), 'timestamp_seconds' => WorkspaceInput::integer($request, 'timestamp_seconds', 86400), 'visibility' => WorkspaceInput::visibility($request, false)],
                        'clip' => ['title' => WorkspaceInput::text($request, 'title', 160), 'start_seconds' => WorkspaceInput::integer($request, 'start_seconds', 86400), 'end_seconds' => WorkspaceInput::integer($request, 'end_seconds', 86400), 'visibility' => WorkspaceInput::visibility($request)],
                        default => throw new \InvalidArgumentException('Unbekannter Eintrag.'),
                    };
                    if ($kind === 'clip' && ($changes['end_seconds'] <= $changes['start_seconds'] || $changes['end_seconds'] - $changes['start_seconds'] > 600)) { throw new \InvalidArgumentException('Ein Clip muss 1 bis 600 Sekunden lang sein.'); }
                }
                $this->collections->mutate($kind, $id, $owner, $request->request->getString('_version'), $action, $changes, $action === 'remove' ? WorkspaceInput::integer($request, 'item_id', 2147483647) : null);
            } catch (\InvalidArgumentException $e) { throw new UnprocessableEntityHttpException($e->getMessage(), $e); }
            $this->addFlash('success', 'Deine Video-Sammlung wurde aktualisiert.');
            return $this->redirectToRoute('app_video_workspace_account');
        }
        $items = $kind === 'watchlist' ? $this->collections->watchlistItems($id) : [];
        $version = $this->collections->version($row);
        return $this->privateResponse($this->render('video_workspace/owned.html.twig', compact('row', 'kind', 'id', 'items', 'version')));
    }
    private function owner(): int
    {
        if (!$this->module->enabled()) { throw $this->createNotFoundException(); }
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isActive() || $user->isLocked() || $user->getId() === null) { throw $this->createAccessDeniedException(); }
        return $user->getId();
    }
    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store'); $response->headers->set('X-Robots-Tag', 'noindex, nofollow'); return $response;
    }
}

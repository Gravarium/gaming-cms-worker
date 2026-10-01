<?php

declare(strict_types=1);

namespace App\Controller;

use App\CompetitionDisputeAdmin\OpenDisputeBoard;
use App\Entity\Competition\Competition;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/competitions/{id}/disputes', name: 'app_admin_competition_dispute_board', requirements: ['id' => '\\d+'], methods: ['GET'])]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminCompetitionDisputeBoardController extends AbstractController
{
    public function __construct(
        private readonly OpenDisputeBoard $board,
        private readonly CmsModuleManager $modules,
    ) {
    }

    public function __invoke(Competition $competition): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('admin/competition_dispute_board/index.html.twig', [
            'competition' => $competition,
            'disputes' => $this->board->forCompetition($competition),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\CompetitionProgression\SingleEliminationProgression;
use App\Entity\Competition\Competition;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/competitions')]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminCompetitionRoundProgressionController extends AbstractController
{
    public function __construct(
        private readonly CmsModuleManager $modules,
        private readonly SingleEliminationProgression $progression,
    ) {
    }

    #[Route('/{id}/advance', name: 'app_admin_competition_advance', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function advance(Competition $competition, Request $request): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('competition-advance-'.$competition->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $round = $this->progression->advance($competition);
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
            return $this->redirectToRoute('app_admin_competition_index');
        }

        $this->addFlash('success', $round === null ? 'Das Finale ist abgeschlossen.' : sprintf('Runde %d wurde erzeugt.', $round));
        return $this->redirectToRoute('app_admin_competition_index');
    }
}

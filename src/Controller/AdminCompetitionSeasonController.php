<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Competition\CompetitionSeason;
use App\Form\Competition\CompetitionSeasonType;
use App\Module\CmsModuleManager;
use App\Repository\Competition\CompetitionSeasonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/competition-seasons')]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminCompetitionSeasonController extends AbstractController
{
    public function __construct(
        private readonly CmsModuleManager $modules,
        private readonly CompetitionSeasonRepository $seasons,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_admin_competition_seasons', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        $season = new CompetitionSeason();
        $form = $this->createForm(CompetitionSeasonType::class, $season)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($season->getEndsAt() !== null && $season->getEndsAt() <= $season->getStartsAt()) {
                $form->get('endsAt')->addError(new FormError('Das Ende muss nach dem Beginn liegen.'));
            } elseif ($season->getGame()?->isEnabled() !== true) {
                $form->get('game')->addError(new FormError('Bitte wähle ein aktives Spiel.'));
            } else {
                $this->entityManager->persist($season);
                $this->entityManager->flush();
                $this->addFlash('success', 'Die Saison wurde angelegt.');

                return $this->redirectToRoute('app_admin_competition_seasons');
            }
        }

        return $this->render('admin/competition/season_index.html.twig', [
            'form' => $form,
            'seasons' => $this->seasons->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], 50),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\User;
use App\Gaming\Competition\CompetitionVisibilityPolicy;
use App\Module\CmsModuleManager;
use App\Widget\CompetitionParticipationQuery;
use App\Widget\MyCompetitionsWidgetProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/account/competitions')]
#[IsGranted('ROLE_USER')]
final class CompetitionParticipationDashboardController extends AbstractController
{
    public function __construct(
        private readonly CompetitionParticipationQuery $participation,
        private readonly CompetitionVisibilityPolicy $visibility,
        private readonly CmsModuleManager $modules,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_competition_participation_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->assertAvailable();
        $captain = $this->currentUser();
        $this->markPersonalized($request);

        return $this->render('account/dashboard.html.twig', [
            'entries' => $this->participation->forCaptain($captain),
        ]);
    }

    #[Route(
        '/{competitionId}/participant/{participantId}/withdraw',
        name: 'app_competition_participation_withdraw',
        requirements: ['competitionId' => '\\d+', 'participantId' => '\\d+'],
        methods: ['POST'],
    )]
    public function withdraw(int $competitionId, int $participantId, Request $request): Response
    {
        $this->assertAvailable();
        $captain = $this->currentUser();
        $this->markPersonalized($request);

        if (!$this->isCsrfTokenValid(
            'competition-withdraw-'.$participantId,
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException();
        }

        $participant = $this->entityManager->getRepository(CompetitionParticipant::class)->find($participantId);
        $competition = $this->entityManager->getRepository(Competition::class)->find($competitionId);
        if (
            !$participant instanceof CompetitionParticipant
            || !$competition instanceof Competition
            || $participant->getCompetition()?->getId() !== $competition->getId()
            || $participant->getCaptain()?->getId() !== $captain->getId()
            || !$this->visibility->canView($competition, $captain)
        ) {
            throw $this->createNotFoundException();
        }

        if (!$competition->isRegistrationOpen() || $participant->getStatus() !== CompetitionParticipant::STATUS_REGISTERED) {
            $this->addFlash('error', 'Diese Anmeldung kann nur während der offenen Registrierung zurückgezogen werden.');

            return $this->redirectToRoute('app_competition_participation_dashboard');
        }

        $participant->withdraw();
        $this->entityManager->flush();
        $this->addFlash('success', 'Deine Anmeldung wurde zurückgezogen.');

        return $this->redirectToRoute('app_competition_participation_dashboard');
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function markPersonalized(Request $request): void
    {
        $request->attributes->set(MyCompetitionsWidgetProvider::PERSONALIZED_ATTRIBUTE, true);
    }
}

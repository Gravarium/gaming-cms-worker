<?php

declare(strict_types=1);

namespace App\Controller;

use App\CompetitionParticipantAdmin\ParticipantManagement;
use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionParticipant;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/competitions/{competition}/participants', requirements: ['competition' => '\\d+'])]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminCompetitionParticipantController extends AbstractController
{
    public function __construct(
        private readonly ParticipantManagement $management,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('', name: 'app_admin_competition_participants', methods: ['GET'])]
    public function index(Competition $competition): Response
    {
        $this->assertAvailable();
        $response = $this->render('admin/competition_participant/index.html.twig', [
            'competition' => $competition,
            ...$this->management->board($competition),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }

    #[Route('/{participant}/seed', name: 'app_admin_competition_participant_seed', requirements: ['participant' => '\\d+'], methods: ['POST'])]
    public function seed(Competition $competition, CompetitionParticipant $participant, Request $request): Response
    {
        $this->assertMutation($competition, $participant, $request, 'seed');
        $raw = $request->request->get('seed');
        if (!is_string($raw) || !preg_match('/^[1-9]\d*$/', $raw) || strlen($raw) > 9) {
            throw new BadRequestHttpException('Die Setzposition ist ungültig.');
        }
        $this->perform(fn () => $this->management->assignSeed((int) $competition->getId(), (int) $participant->getId(), (int) $raw));

        return $this->redirectToBoard($competition);
    }

    #[Route('/{participant}/withdraw', name: 'app_admin_competition_participant_withdraw', requirements: ['participant' => '\\d+'], methods: ['POST'])]
    public function withdraw(Competition $competition, CompetitionParticipant $participant, Request $request): Response
    {
        $this->assertMutation($competition, $participant, $request, 'withdraw');
        $this->perform(fn () => $this->management->withdraw((int) $competition->getId(), (int) $participant->getId(), $this->rawReason($request)));

        return $this->redirectToBoard($competition);
    }

    #[Route('/{participant}/disqualify', name: 'app_admin_competition_participant_disqualify', requirements: ['participant' => '\\d+'], methods: ['POST'])]
    public function disqualify(Competition $competition, CompetitionParticipant $participant, Request $request): Response
    {
        $this->assertMutation($competition, $participant, $request, 'disqualify');
        $this->perform(fn () => $this->management->disqualify((int) $competition->getId(), (int) $participant->getId(), $this->rawReason($request)));

        return $this->redirectToBoard($competition);
    }

    private function assertMutation(Competition $competition, CompetitionParticipant $participant, Request $request, string $action): void
    {
        $this->assertAvailable();
        if ($participant->getCompetition()?->getId() !== $competition->getId()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('competition-participant-'.$action.'-'.$participant->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
    }

    private function rawReason(Request $request): ?string
    {
        $reason = $request->request->get('reason');
        if ($reason !== null && !is_string($reason)) {
            throw new BadRequestHttpException('Die Begründung ist ungültig.');
        }

        return $reason;
    }

    /** @param callable(): void $operation */
    private function perform(callable $operation): void
    {
        try {
            $operation();
        } catch (\DomainException|\InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }
    }

    private function redirectToBoard(Competition $competition): Response
    {
        return $this->redirectToRoute('app_admin_competition_participants', ['competition' => $competition->getId()]);
    }
}

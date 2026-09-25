<?php

declare(strict_types=1);

namespace App\Controller\Competition;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionDispute;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionMatchEvidence;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\User;
use App\Form\Competition\CompetitionRegistrationType;
use App\Gaming\Competition\CompetitionResultPolicy;
use App\Gaming\Competition\CompetitionVisibilityPolicy;
use App\Module\CmsModuleManager;
use App\Repository\Competition\CompetitionMatchRepository;
use App\Repository\Competition\CompetitionParticipantRepository;
use App\Repository\Competition\CompetitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route('/competitions')]
final class CompetitionController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitions,
        private readonly CompetitionParticipantRepository $participants,
        private readonly CompetitionMatchRepository $matches,
        private readonly CompetitionVisibilityPolicy $visibility,
        private readonly CompetitionResultPolicy $results,
        private readonly CmsModuleManager $modules,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_competition_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->assertAvailable();
        return $this->render('competition/index.html.twig', ['competitions' => $this->competitions->publicCompetitions()]);
    }

    #[Route('/{id}', name: 'app_competition_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(Competition $competition): Response
    {
        $this->assertAvailable();
        $viewer = $this->getUser();
        if ($viewer !== null && !$viewer instanceof User) { throw $this->createNotFoundException(); }
        if (!$this->visibility->canView($competition, $viewer instanceof User ? $viewer : null)) { throw $this->createNotFoundException(); }
        return $this->render('competition/show.html.twig', [
            'competition' => $competition,
            'participants' => $this->participants->forCompetition($competition),
            'matches' => $this->matches->forCompetition($competition),
        ]);
    }

    #[Route('/{id}/register', name: 'app_competition_register', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function register(Competition $competition, Request $request): Response
    {
        $this->assertAvailable();
        $user = $this->currentUser();
        if (!$this->visibility->canView($competition, $user) || !$competition->isRegistrationOpen()) { throw $this->createNotFoundException(); }
        if ($this->participants->forCompetitionAndUser($competition, $user) !== null) { $this->addFlash('error', 'Du bist bereits registriert.'); return $this->redirectToRoute('app_competition_show', ['id' => $competition->getId()]); }

        $participant = (new CompetitionParticipant())->setCompetition($competition)->setCaptain($user);
        $form = $this->createForm(CompetitionRegistrationType::class, $participant, ['competition_mode' => $competition->getMode()])->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($competition->getMode() === Competition::MODE_SOLO && $participant->getKind() !== CompetitionParticipant::KIND_SOLO) {
                $form->addError(new \Symfony\Component\Form\FormError('Diese Competition akzeptiert nur Einzelanmeldungen.'));
            }
            if ($competition->getMode() === Competition::MODE_TEAM && $participant->getKind() !== CompetitionParticipant::KIND_TEAM) {
                $form->addError(new \Symfony\Component\Form\FormError('Diese Competition akzeptiert nur Team-Anmeldungen.'));
            }
            $activeCount = count(array_filter($this->participants->forCompetition($competition), static fn (CompetitionParticipant $item): bool => $item->isActive()));
            if ($competition->getMaxParticipants() !== null && $activeCount >= $competition->getMaxParticipants()) {
                $form->addError(new \Symfony\Component\Form\FormError('Die maximale Teilnehmerzahl ist erreicht.'));
            }
            if ($form->isValid()) {
                $participant->setRosterUserIds($user->getId() === null ? [] : [$user->getId()]);
                $this->entityManager->persist($participant);
                $this->entityManager->flush();
                $this->addFlash('success', 'Die Anmeldung wurde gespeichert.');
                return $this->redirectToRoute('app_competition_show', ['id' => $competition->getId()]);
            }
        }
        return $this->render('competition/register.html.twig', ['competition' => $competition, 'form' => $form]);
    }

    #[Route('/{id}/participant/{participant}/check-in', name: 'app_competition_check_in', requirements: ['id' => '\\d+', 'participant' => '\\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function checkIn(Competition $competition, CompetitionParticipant $participant, Request $request): Response
    {
        $this->assertParticipantCompetition($competition, $participant);
        $user = $this->currentUser();
        if (!$participant->containsUser($user) || !$this->isCsrfTokenValid('competition-check-in-'.$participant->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $participant->checkIn();
        $this->entityManager->flush();
        return $this->redirectToRoute('app_competition_show', ['id' => $competition->getId()]);
    }

    #[Route('/{id}/match/{match}/result', name: 'app_competition_match_result', requirements: ['id' => '\\d+', 'match' => '\\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function result(Competition $competition, CompetitionMatch $match, Request $request): Response
    {
        $this->assertMatchCompetition($competition, $match);
        $user = $this->currentUser();
        if (!$this->isCsrfTokenValid('competition-result-'.$match->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $participant = $this->participantFromRequest($match, (int) $request->request->get('participant'));
        if (!$this->results->canSubmit($match, $participant, $user)) { throw $this->createAccessDeniedException(); }
        $scoreA = $this->score($request, 'score_a');
        $scoreB = $this->score($request, 'score_b');
        $match->submitResult($participant, $scoreA, $scoreB, $user);
        $this->entityManager->flush();
        return $this->redirectToRoute('app_competition_show', ['id' => $competition->getId()]);
    }

    #[Route('/{id}/match/{match}/confirm', name: 'app_competition_match_confirm', requirements: ['id' => '\\d+', 'match' => '\\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function confirm(Competition $competition, CompetitionMatch $match, Request $request): Response
    {
        $this->assertMatchCompetition($competition, $match);
        $user = $this->currentUser();
        if (!$this->isCsrfTokenValid('competition-confirm-'.$match->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $participant = $this->participantFromRequest($match, (int) $request->request->get('participant'));
        if (!$this->results->canConfirm($match, $participant, $user)) { throw $this->createAccessDeniedException(); }
        $match->confirmResult($participant);
        $this->entityManager->flush();
        return $this->redirectToRoute('app_competition_show', ['id' => $competition->getId()]);
    }

    #[Route('/{id}/match/{match}/dispute', name: 'app_competition_match_dispute', requirements: ['id' => '\\d+', 'match' => '\\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function dispute(Competition $competition, CompetitionMatch $match, Request $request): Response
    {
        $this->assertMatchCompetition($competition, $match);
        $user = $this->currentUser();
        if (!$this->isCsrfTokenValid('competition-dispute-'.$match->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $participant = $this->participantFromRequest($match, (int) $request->request->get('participant'));
        if (!$this->results->canOpenDispute($match, $participant, $user)) { throw $this->createAccessDeniedException(); }
        $reason = trim((string) $request->request->get('reason'));
        if ($reason === '') { throw new BadRequestHttpException('Eine Anfechtung benötigt eine Begründung.'); }
        $match->markDisputed();
        $dispute = (new CompetitionDispute())->setMatch($match)->setOpenedBy($user)->setReason($reason);
        $this->entityManager->persist($dispute);
        $this->entityManager->flush();
        return $this->redirectToRoute('app_competition_show', ['id' => $competition->getId()]);
    }

    #[Route('/{id}/match/{match}/evidence', name: 'app_competition_match_evidence', requirements: ['id' => '\\d+', 'match' => '\\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function evidence(Competition $competition, CompetitionMatch $match, Request $request): Response
    {
        $this->assertMatchCompetition($competition, $match);
        $user = $this->currentUser();
        if (!$this->isCsrfTokenValid('competition-evidence-'.$match->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $participant = $this->participantFromRequest($match, (int) $request->request->get('participant'));
        if (!$participant->containsUser($user)) { throw $this->createAccessDeniedException(); }
        $locator = trim((string) $request->request->get('locator'));
        $scheme = strtolower((string) parse_url($locator, PHP_URL_SCHEME));
        if (!filter_var($locator, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) { throw new BadRequestHttpException('Der Beleg muss eine gültige HTTP(S)-URL sein.'); }
        $type = (string) $request->request->get('type', CompetitionMatchEvidence::TYPE_URL);
        if (!in_array($type, [CompetitionMatchEvidence::TYPE_SCREENSHOT, CompetitionMatchEvidence::TYPE_VIDEO, CompetitionMatchEvidence::TYPE_URL], true)) { throw new BadRequestHttpException('Der Belegtyp ist ungültig.'); }
        $evidence = (new CompetitionMatchEvidence())->setMatch($match)->setSubmittedBy($user)->setLocator($locator)->setType($type);
        $this->entityManager->persist($evidence);
        $this->entityManager->flush();
        return $this->redirectToRoute('app_competition_show', ['id' => $competition->getId()]);
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) { throw $this->createNotFoundException(); }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        return $user;
    }

    private function assertParticipantCompetition(Competition $competition, CompetitionParticipant $participant): void
    {
        $this->assertAvailable();
        if ($participant->getCompetition()?->getId() !== $competition->getId()) { throw $this->createNotFoundException(); }
    }

    private function assertMatchCompetition(Competition $competition, CompetitionMatch $match): void
    {
        $this->assertAvailable();
        if ($match->getCompetition()?->getId() !== $competition->getId()) { throw $this->createNotFoundException(); }
    }

    private function participantFromRequest(CompetitionMatch $match, int $id): CompetitionParticipant
    {
        foreach ([$match->getParticipantA(), $match->getParticipantB()] as $participant) {
            if ($participant instanceof CompetitionParticipant && $participant->getId() === $id) { return $participant; }
        }
        throw $this->createAccessDeniedException();
    }

    private function score(Request $request, string $key): int
    {
        $raw = $request->request->get($key);
        if (!is_string($raw) || !preg_match('/^\d+$/', $raw)) { throw new BadRequestHttpException('Scores müssen nichtnegative ganze Zahlen sein.'); }
        return (int) $raw;
    }
}

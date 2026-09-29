<?php

declare(strict_types=1);

namespace App\Controller;

use App\CompetitionRoster\CompetitionRosterInviteLink;
use App\CompetitionRoster\CompetitionRosterQuery;
use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\User;
use App\Module\CmsModuleManager;
use App\Repository\Competition\CompetitionParticipantRepository;
use App\Repository\Competition\CompetitionRepository;
use App\Repository\UserRepository;
use App\Widget\MyCompetitionRostersWidgetProvider;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CompetitionRosterController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRosterQuery $rosters,
        private readonly CompetitionRosterInviteLink $inviteLinks,
        private readonly CompetitionRepository $competitions,
        private readonly CompetitionParticipantRepository $participants,
        private readonly UserRepository $users,
        private readonly CmsModuleManager $modules,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/account/competition-rosters', name: 'app_competition_roster_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        $this->assertAvailable($request);
        $captain = $this->currentUser();

        return $this->privateResponse($this->render('@CompetitionRoster/account/dashboard.html.twig', [
            'teams' => $this->rosters->forCaptain($captain),
        ]));
    }

    #[Route(
        '/account/competition-rosters/{competitionId}/participants/{participantId}/invite',
        name: 'app_competition_roster_invite',
        requirements: ['competitionId' => '\\d+', 'participantId' => '\\d+'],
        methods: ['POST'],
    )]
    public function invite(int $competitionId, int $participantId, Request $request): Response
    {
        $this->assertAvailable($request);
        $captain = $this->currentUser();
        if (!$this->isCsrfTokenValid('competition-roster-invite-'.$participantId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges CSRF-Token. Bitte lade die Seite neu.');
        }

        [$competition, $participant] = $this->loadPublicTeam($competitionId, $participantId);
        if ($participant->getCaptain()?->getId() !== $captain->getId()) {
            throw $this->createNotFoundException();
        }
        if (
            $competition->getStatus() !== Competition::STATUS_OPEN
            || $participant->getStatus() !== CompetitionParticipant::STATUS_REGISTERED
            || $participant->isCheckedIn()
        ) {
            $this->addFlash('error', 'Einladungen können nur vor dem Check-in während der offenen Anmeldung erstellt werden.');

            return $this->dashboardRedirect();
        }

        $email = mb_strtolower(trim($request->request->getString('email')));
        if ($email === '' || mb_strlen($email) > 180 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->addFlash('error', 'Bitte gib eine gültige E-Mail-Adresse mit höchstens 180 Zeichen ein.');

            return $this->dashboardRedirect();
        }

        $teammate = $this->users->findOneBy(['email' => $email]);
        if (!$teammate instanceof User || !$teammate->isActive() || !$teammate->isEmailVerified()) {
            $this->addFlash('error', 'Die Einladung benötigt die bestätigte E-Mail-Adresse eines aktiven Kontos.');

            return $this->dashboardRedirect();
        }
        if ($participant->containsUser($teammate)) {
            $this->addFlash('error', 'Dieses Konto ist bereits Teil des Teams.');

            return $this->dashboardRedirect();
        }
        if ($this->openSlots($competition, $participant) < 1) {
            $this->addFlash('error', 'Das Team ist bereits vollständig.');

            return $this->dashboardRedirect();
        }

        $token = $this->inviteLinks->issue($competition, $participant, $captain, $email);
        $url = $this->generateUrl('app_competition_roster_join', [
            'competitionId' => $competitionId,
            'participantId' => $participantId,
            'token' => $token,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->flashBag($request)->add('competition_roster_invite_link', $url);
        $this->addFlash('success', 'Der einmalige Einladungslink ist eine Stunde gültig. Teile ihn nur mit dem eingeladenen Teammitglied.');

        return $this->dashboardRedirect();
    }

    #[Route(
        '/account/competition-rosters/{competitionId}/participants/{participantId}/members/{memberId}/remove',
        name: 'app_competition_roster_remove_member',
        requirements: ['competitionId' => '\\d+', 'participantId' => '\\d+', 'memberId' => '\\d+'],
        methods: ['POST'],
    )]
    public function removeMember(int $competitionId, int $participantId, int $memberId, Request $request): Response
    {
        $this->assertAvailable($request);
        $captain = $this->currentUser();
        if (!$this->isCsrfTokenValid(
            'competition-roster-remove-'.$participantId.'-'.$memberId,
            $request->request->getString('_token'),
        )) {
            throw $this->createAccessDeniedException('Ungültiges CSRF-Token. Bitte lade die Seite neu.');
        }

        $result = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($competitionId, $participantId, $memberId, $captain): string {
            $participant = $entityManager->find(CompetitionParticipant::class, $participantId);
            if (!$participant instanceof CompetitionParticipant) {
                throw $this->createNotFoundException();
            }
            $entityManager->refresh($participant, LockMode::PESSIMISTIC_WRITE);

            $competition = $participant->getCompetition();
            if (!$competition instanceof Competition || $participant->getCompetition()?->getId() !== $competitionId) {
                throw $this->createNotFoundException();
            }
            $entityManager->refresh($competition, LockMode::PESSIMISTIC_READ);
            $this->assertPublicTeam($competition, $participant, $competitionId, $participantId);
            if ($participant->getCaptain()?->getId() !== $captain->getId()) {
                throw $this->createNotFoundException();
            }
            if ($memberId === $captain->getId()) {
                return 'captain';
            }
            if (
                $competition->getStatus() !== Competition::STATUS_OPEN
                || $participant->getStatus() !== CompetitionParticipant::STATUS_REGISTERED
                || $participant->isCheckedIn()
            ) {
                return 'closed';
            }
            if (!in_array($memberId, $participant->getRosterUserIds(), true)) {
                return 'not_member';
            }

            $remainingIds = array_values(array_filter(
                $participant->getRosterUserIds(),
                static fn (int $id): bool => $id !== $memberId,
            ));
            $participant->setRosterUserIds($remainingIds);
            $entityManager->flush();

            return 'removed';
        });

        if ($result === 'removed') {
            $this->addFlash('success', 'Das Teammitglied wurde aus dem offenen Team entfernt.');
        } elseif ($result === 'captain') {
            $this->addFlash('error', 'Die Teamleitung kann sich nicht selbst aus dem Team entfernen.');
        } elseif ($result === 'not_member') {
            $this->addFlash('error', 'Dieses Konto gehört nicht mehr zum Team.');
        } else {
            $this->addFlash('error', 'Mitglieder können nur vor dem Check-in und während der offenen Anmeldung entfernt werden.');
        }

        return $this->dashboardRedirect();
    }

    #[Route(
        '/competitions/{competitionId}/participants/{participantId}/roster/join/{token}',
        name: 'app_competition_roster_join',
        requirements: [
            'competitionId' => '\\d+',
            'participantId' => '\\d+',
            'token' => '[A-Za-z0-9_-]+\\.[a-f0-9]{64}',
        ],
        methods: ['GET', 'POST'],
    )]
    public function join(int $competitionId, int $participantId, string $token, Request $request): Response
    {
        $this->assertAvailable($request);
        $user = $this->currentUser();
        [$competition, $participant] = $this->loadPublicTeam($competitionId, $participantId);

        if ($request->isMethod('GET')) {
            if (
                $competition->getStatus() !== Competition::STATUS_OPEN
                || $participant->getStatus() !== CompetitionParticipant::STATUS_REGISTERED
                || $participant->isCheckedIn()
            ) {
                $this->addFlash('error', 'Die Competition-Anmeldung oder der Teamkader ist nicht mehr offen.');

                return $this->privateResponse($this->redirectToRoute('app_competition_show', ['id' => $competitionId]));
            }
            if (
                !$this->inviteLinks->isValid($token, $competition, $participant, $user)
            ) {
                throw $this->createNotFoundException('Diese Einladung ist ungültig oder nicht mehr verfügbar.');
            }
            if ($this->openSlots($competition, $participant) < 1) {
                $this->addFlash('error', 'Das Team ist bereits vollständig.');

                return $this->privateResponse($this->redirectToRoute('app_competition_show', ['id' => $competitionId]));
            }

            return $this->privateResponse($this->render('@CompetitionRoster/account/join.html.twig', [
                'competition' => $competition,
                'participant' => $participant,
            ]));
        }

        if (!$this->isCsrfTokenValid('competition-roster-accept-'.$participantId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges CSRF-Token. Bitte lade die Einladung neu.');
        }

        $accepted = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($competitionId, $participantId, $token, $user): bool {
            $participant = $entityManager->find(CompetitionParticipant::class, $participantId);
            if (!$participant instanceof CompetitionParticipant) {
                throw $this->createNotFoundException();
            }
            $entityManager->refresh($participant, LockMode::PESSIMISTIC_WRITE);

            $competition = $participant->getCompetition();
            if (!$competition instanceof Competition || $competition->getId() !== $competitionId) {
                throw $this->createNotFoundException();
            }
            $entityManager->refresh($competition, LockMode::PESSIMISTIC_READ);
            $this->assertPublicTeam($competition, $participant, $competitionId, $participantId);

            $userId = $user->getId();
            if (
                $competition->getStatus() !== Competition::STATUS_OPEN
                || $participant->getStatus() !== CompetitionParticipant::STATUS_REGISTERED
                || $participant->isCheckedIn()
                || $userId === null
                || !$user->isActive()
                || !$user->isEmailVerified()
                || $participant->containsUser($user)
                || $this->openSlots($competition, $participant) < 1
                || !$this->inviteLinks->consume($token, $competition, $participant, $user)
            ) {
                return false;
            }

            $roster = $participant->getRosterUserIds();
            $roster[] = $userId;
            $participant->setRosterUserIds($roster);
            $entityManager->flush();

            return true;
        });

        $this->flashBag($request)->add('competition_roster_join_result_'.$participantId, $accepted ? 'joined' : 'failed');

        return $this->privateResponse($this->redirectToRoute('app_competition_roster_join_result', [
            'competitionId' => $competitionId,
            'participantId' => $participantId,
        ]));
    }

    #[Route(
        '/account/competition-rosters/{competitionId}/participants/{participantId}/join-result',
        name: 'app_competition_roster_join_result',
        requirements: ['competitionId' => '\\d+', 'participantId' => '\\d+'],
        methods: ['GET'],
    )]
    public function joinResult(int $competitionId, int $participantId, Request $request): Response
    {
        $this->assertAvailable($request);
        $user = $this->currentUser();
        [$competition, $participant] = $this->loadPublicTeam($competitionId, $participantId);

        $messages = $this->flashBag($request)->get('competition_roster_join_result_'.$participantId);
        if (!in_array($messages[0] ?? null, ['joined', 'failed'], true)) {
            throw $this->createNotFoundException();
        }

        $userId = $user->getId();
        $joined = $userId !== null
            && $participant->getCaptain()?->getId() !== $userId
            && in_array($userId, $participant->getRosterUserIds(), true);

        return $this->privateResponse($this->render('@CompetitionRoster/account/join.html.twig', [
            'competition' => $competition,
            'participant' => $participant,
            'result' => $joined ? 'joined' : 'failed',
        ]));
    }

    private function assertAvailable(Request $request): void
    {
        $request->attributes->set(MyCompetitionRostersWidgetProvider::PERSONALIZED_ATTRIBUTE, true);
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

    private function flashBag(Request $request): FlashBagInterface
    {
        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            throw new \LogicException('The session cannot store flash messages.');
        }

        return $session->getFlashBag();
    }

    /** @return array{Competition, CompetitionParticipant} */
    private function loadPublicTeam(int $competitionId, int $participantId): array
    {
        $competition = $this->competitions->find($competitionId);
        $participant = $this->participants->find($participantId);
        if (!$competition instanceof Competition || !$participant instanceof CompetitionParticipant) {
            throw $this->createNotFoundException();
        }
        $this->assertPublicTeam($competition, $participant, $competitionId, $participantId);

        return [$competition, $participant];
    }

    private function assertPublicTeam(
        Competition $competition,
        CompetitionParticipant $participant,
        int $competitionId,
        int $participantId,
    ): void {
        if (
            $competition->getId() !== $competitionId
            || $participant->getId() !== $participantId
            || $participant->getCompetition()?->getId() !== $competitionId
            || $competition->getMode() !== Competition::MODE_TEAM
            || $competition->isPublic() !== true
            || $competition->getGame()?->isEnabled() !== true
            || $participant->getKind() !== CompetitionParticipant::KIND_TEAM
        ) {
            throw $this->createNotFoundException();
        }
    }

    private function openSlots(Competition $competition, CompetitionParticipant $participant): int
    {
        $ids = $participant->getRosterUserIds();
        $captainId = $participant->getCaptain()?->getId();
        if ($captainId !== null) {
            $ids[] = $captainId;
        }
        $ids = array_values(array_unique(array_filter(
            $ids,
            static fn (mixed $id): bool => self::isPositiveUserId($id),
        )));

        return max(0, $competition->getTeamSize() - count($ids));
    }

    private function dashboardRedirect(): Response
    {
        return $this->privateResponse($this->redirectToRoute('app_competition_roster_dashboard'));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private static function isPositiveUserId(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }
}

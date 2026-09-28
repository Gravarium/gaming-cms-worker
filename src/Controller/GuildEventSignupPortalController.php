<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Guild;
use App\Entity\GuildEvent;
use App\Entity\GuildEventSignup;
use App\Entity\GuildMember;
use App\Entity\User;
use App\Repository\GuildEventSignupPortalRepository;
use App\Repository\GuildEventSignupRepository;
use App\Service\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/guild-area/event-signups', name: 'app_guild_event_signup_portal')]
#[IsGranted('ROLE_USER')]
final class GuildEventSignupPortalController extends AbstractController
{
    public function __construct(
        private readonly GuildEventSignupPortalRepository $signups,
        private readonly GuildEventSignupRepository $guildSignups,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('', name: '_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = $request->query->all();
        $pageValue = $query['page'] ?? '1';
        if (!is_string($pageValue) || preg_match('/^[1-9][0-9]*$/D', $pageValue) !== 1) {
            throw new BadRequestHttpException('Ungültige Seitennummer.');
        }

        $user = $this->currentUser();
        $now = new \DateTimeImmutable();
        $total = $this->signups->countUpcomingForUser($user, $now);
        $lastPage = max(1, (int) ceil($total / GuildEventSignupPortalRepository::PAGE_SIZE));
        $page = min((int) $pageValue, $lastPage);

        $response = $this->render('guild_event_signup_portal/index.html.twig', [
            'signups' => $this->signups->pageUpcomingForUser($user, $now, $page),
            'page' => $page,
            'lastPage' => $lastPage,
            'total' => $total,
        ]);

        return $this->privateResponse($response);
    }

    #[Route('/{signupId}/withdraw', name: '_withdraw', requirements: ['signupId' => '\d+'], methods: ['POST'])]
    public function withdraw(int $signupId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('guild-event-signup-withdraw-'.$signupId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->currentUser();
        $now = new \DateTimeImmutable();
        $candidate = $this->signups->upcomingSignupForUser($signupId, $user, $now);
        if (!$candidate instanceof GuildEventSignup || !$candidate->getEvent() instanceof GuildEvent) {
            throw $this->createNotFoundException();
        }

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($candidate, $user, $signupId): void {
            $event = $candidate->getEvent();

            // The existing signup flow locks this event before checking capacity; use the same lock boundary.
            $entityManager->refresh($event, LockMode::PESSIMISTIC_WRITE);
            $entityManager->refresh($candidate, LockMode::PESSIMISTIC_WRITE);

            $member = $candidate->getMember();
            $guild = $event->getGuild();
            if (!$member instanceof GuildMember || !$guild instanceof Guild) {
                throw $this->createNotFoundException();
            }

            $entityManager->refresh($member, LockMode::PESSIMISTIC_READ);
            $entityManager->refresh($guild, LockMode::PESSIMISTIC_READ);

            $now = new \DateTimeImmutable();
            $ownsSignup = $candidate->getUser()?->getId() === $user->getId()
                && $member->getUser()?->getId() === $user->getId()
                && $member->isActive()
                && $member->getGuild()?->getId() === $guild->getId()
                && $event->getGuild()?->getId() === $guild->getId()
                && $guild->isEnabled()
                && $event->getStatus() === GuildEvent::STATUS_PLANNED
                && $event->getStartsAt() > $now
                && in_array($candidate->getResponse(), [
                    GuildEventSignup::GOING,
                    GuildEventSignup::MAYBE,
                    GuildEventSignup::WAITLIST,
                ], true);

            if (!$ownsSignup) {
                throw $this->createNotFoundException();
            }

            $candidate->setResponse(GuildEventSignup::DECLINED)->setNote(null);
            $this->audit->record(
                'guild_event.signup_withdraw',
                $event,
                $event->getId(),
                'A member withdrew an event signup.',
                ['signup_id' => $signupId, 'member_id' => $member->getId()],
            );
        });

        $this->addFlash('success', 'Deine Termin-Anmeldung wurde zurückgezogen.');
        $response = $this->redirectToRoute('app_guild_event_signup_portal_index');

        return $this->privateResponse($response);
    }

    #[Route('/{signupId}/update', name: '_update', requirements: ['signupId' => '\\d+'], methods: ['POST'])]
    public function update(int $signupId, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('guild-event-signup-update-'.$signupId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $values = $request->request->all();
        $requestedResponse = $values['response'] ?? null;
        $requestedRole = $values['role'] ?? null;
        if (!is_string($requestedResponse)
            || !in_array($requestedResponse, [GuildEventSignup::GOING, GuildEventSignup::MAYBE, GuildEventSignup::DECLINED], true)
            || !is_string($requestedRole)
            || !in_array($requestedRole, ['tank', 'heal', 'damage', 'support', 'other'], true)
        ) {
            $this->addFlash('error', 'Bitte wähle eine gültige Antwort und Rolle.');

            return $this->privateResponse($this->redirectToRoute('app_guild_event_signup_portal_index'));
        }

        $user = $this->currentUser();
        $candidate = $this->signups->upcomingSignupForUser($signupId, $user, new \DateTimeImmutable());
        if (!$candidate instanceof GuildEventSignup || !$candidate->getEvent() instanceof GuildEvent) {
            throw $this->createNotFoundException();
        }

        $response = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($candidate, $user, $signupId, $requestedResponse, $requestedRole): string {
            $event = $candidate->getEvent();
            $entityManager->refresh($event, LockMode::PESSIMISTIC_WRITE);
            $entityManager->refresh($candidate, LockMode::PESSIMISTIC_WRITE);

            $member = $candidate->getMember();
            $guild = $event->getGuild();
            if (!$member instanceof GuildMember || !$guild instanceof Guild) {
                throw $this->createNotFoundException();
            }

            $entityManager->refresh($member, LockMode::PESSIMISTIC_READ);
            $entityManager->refresh($guild, LockMode::PESSIMISTIC_READ);

            $now = new \DateTimeImmutable();
            $ownsSignup = $candidate->getUser()?->getId() === $user->getId()
                && $member->getUser()?->getId() === $user->getId()
                && $member->isActive()
                && $member->getGuild()?->getId() === $guild->getId()
                && $event->getGuild()?->getId() === $guild->getId()
                && $guild->isEnabled()
                && $event->getStatus() === GuildEvent::STATUS_PLANNED
                && $event->getStartsAt() > $now
                && in_array($candidate->getResponse(), [
                    GuildEventSignup::GOING,
                    GuildEventSignup::MAYBE,
                    GuildEventSignup::WAITLIST,
                ], true);

            if (!$ownsSignup) {
                throw $this->createNotFoundException();
            }

            $response = $requestedResponse;
            if ($response === GuildEventSignup::GOING
                && $candidate->getResponse() !== GuildEventSignup::GOING
                && $event->getMaxParticipants() !== null
                && $this->guildSignups->confirmedCount($event) >= $event->getMaxParticipants()
            ) {
                $response = GuildEventSignup::WAITLIST;
            }

            // Only the response and role are editable here; keep the private note and attendance record intact.
            $candidate->setResponse($response)->setRole($requestedRole);
            $this->audit->record(
                'guild_event.signup_update',
                $event,
                $event->getId(),
                'A member updated an event signup.',
                ['signup_id' => $signupId, 'member_id' => $member->getId(), 'response' => $response, 'role' => $requestedRole],
            );

            return $response;
        });

        $this->addFlash('success', $response === GuildEventSignup::WAITLIST
            ? 'Der Termin ist voll. Du stehst auf der Warteliste.'
            : 'Deine Anmeldung wurde aktualisiert.');
        $response = $this->redirectToRoute('app_guild_event_signup_portal_index');

        return $this->privateResponse($response);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}

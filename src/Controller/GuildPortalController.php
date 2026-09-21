<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Guild;
use App\Entity\GuildEvent;
use App\Entity\GuildEventSignup;
use App\Entity\GuildMember;
use App\Entity\GuildTeam;
use App\Entity\MemberNotification;
use App\Entity\User;
use App\Repository\GuildAnnouncementRepository;
use App\Repository\GuildEventRepository;
use App\Repository\GuildEventSignupRepository;
use App\Repository\GuildMemberRepository;
use App\Repository\GuildTeamRepository;
use App\Repository\MemberNotificationRepository;
use App\Service\AuditLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/guild-area')]
#[IsGranted('ROLE_USER')]
final class GuildPortalController extends AbstractController
{
    public function __construct(
        private readonly GuildMemberRepository $members,
        private readonly GuildEventRepository $events,
        private readonly GuildEventSignupRepository $signups,
        private readonly GuildAnnouncementRepository $announcements,
        private readonly GuildTeamRepository $teams,
        private readonly MemberNotificationRepository $notifications,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $audit,
    ) {}

    #[Route('', name: 'app_guild_portal_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->currentUser();
        return $this->render('guild_portal/index.html.twig', [
            'memberships' => $this->members->forUser($user),
            'notifications' => $this->notifications->latestForUser($user),
            'unreadNotifications' => $this->notifications->unreadCount($user),
        ]);
    }

    #[Route('/notification/{id}/read', name: 'app_member_notification_read', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function readNotification(MemberNotification $notification, Request $request): Response
    {
        if ($notification->getUser()?->getId() !== $this->currentUser()->getId()) { throw $this->createAccessDeniedException(); }
        if (!$this->isCsrfTokenValid('member-notification-'.$notification->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $notification->markRead(); $this->entityManager->flush();
        return $notification->getLink() ? $this->redirect($notification->getLink()) : $this->redirectToRoute('app_guild_portal_index');
    }

    #[Route('/notifications/read-all', name: 'app_member_notification_read_all', methods: ['POST'])]
    public function readAllNotifications(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('member-notifications-read-all', (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        foreach ($this->notifications->findBy(['user' => $this->currentUser(), 'readAt' => null]) as $notification) { $notification->markRead(); }
        $this->entityManager->flush();
        return $this->redirectToRoute('app_guild_portal_index');
    }


    #[Route('/{id}/calendar.ics', name: 'app_guild_calendar', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function calendar(Guild $guild): Response
    {
        $characters = $this->characters($guild);
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Gaming CMS//Guild Calendar//DE', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];
        foreach ($this->visibleEvents($guild, $characters) as $event) {
            $end = $event->getEndsAt() ?? $event->getStartsAt()->modify('+2 hours');
            array_push($lines,
                'BEGIN:VEVENT',
                'UID:guild-event-'.$event->getId().'@gaming-cms',
                'DTSTAMP:'.$event->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z'),
                'DTSTART:'.$event->getStartsAt()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z'),
                'DTEND:'.$end->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis\\Z'),
                'SUMMARY:'.$this->escapeCalendar($event->getTitle()),
                'DESCRIPTION:'.$this->escapeCalendar($event->getDescription()),
                'LOCATION:'.$this->escapeCalendar($event->getLocation() ?? ''),
                'STATUS:'.($event->getStatus() === GuildEvent::STATUS_CANCELLED ? 'CANCELLED' : 'CONFIRMED'),
                'END:VEVENT',
            );
        }
        $lines[] = 'END:VCALENDAR';

        return new Response(implode("\r\n", $lines)."\r\n", Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="guild-'.$guild->getId().'-calendar.ics"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    #[Route('/{id}', name: 'app_guild_portal_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Guild $guild): Response
    {
        $characters = $this->characters($guild);
        return $this->render('guild_portal/show.html.twig', ['guild' => $guild, 'characters' => $characters, 'members' => $this->members->activeForGuild($guild), 'teams' => $this->teams->forGuild($guild), 'events' => $this->visibleEvents($guild, $characters), 'announcements' => $this->announcements->forGuild($guild), 'signupRepository' => $this->signups]);
    }

    #[Route('/{id}/event/{event}/signup', name: 'app_guild_event_signup', requirements: ['id' => '\\d+', 'event' => '\\d+'], methods: ['POST'])]
    public function signup(Guild $guild, GuildEvent $event, Request $request): Response
    {
        if ($event->getGuild()?->getId() !== $guild->getId()) { throw $this->createNotFoundException(); }
        if (!$this->isCsrfTokenValid('event-signup-'.$event->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }

        $characters = $this->characters($guild);
        $memberId = (int) $request->request->get('member');
        $member = null;
        foreach ($characters as $candidate) {
            if ($candidate->getId() === $memberId) { $member = $candidate; break; }
        }
        if (!$member instanceof GuildMember) { throw $this->createAccessDeniedException('Dieser Charakter gehört nicht zu deinem Konto.'); }

        $requestedResponse = (string) $request->request->get('response');
        if (!in_array($requestedResponse, [GuildEventSignup::GOING, GuildEventSignup::MAYBE, GuildEventSignup::DECLINED], true)) {
            throw $this->createNotFoundException();
        }
        $role = (string) $request->request->get('role', 'other');
        if (!in_array($role, ['tank', 'heal', 'damage', 'support', 'other'], true)) { $role = 'other'; }
        $note = (string) $request->request->get('note');
        $actor = $this->currentUser();

        $response = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($event, $characters, $member, $requestedResponse, $role, $note, $actor): string {
            $entityManager->refresh($event, LockMode::PESSIMISTIC_WRITE);

            if ($event->getStatus() !== GuildEvent::STATUS_PLANNED) {
                throw $this->createNotFoundException('Für diesen Termin sind keine Anmeldungen mehr möglich.');
            }
            if (!$this->isVisibleToCharacters($event, $characters)) {
                throw $this->createAccessDeniedException('Dieser Termin gehört zu einem anderen Team.');
            }

            $signup = $this->signups->forEventAndMember($event, $member)
                ?? (new GuildEventSignup())->setEvent($event)->setMember($member)->setUser($actor);
            $wasConfirmed = $signup->getId() !== null && $signup->getResponse() === GuildEventSignup::GOING;
            $response = $requestedResponse;
            if ($response === GuildEventSignup::GOING
                && !$wasConfirmed
                && $event->getMaxParticipants() !== null
                && $this->signups->confirmedCount($event) >= $event->getMaxParticipants()
            ) {
                $response = GuildEventSignup::WAITLIST;
            }

            $signup->setResponse($response)->setRole($role)->setNote($note);
            if ($signup->getId() === null) { $entityManager->persist($signup); }
            $this->audit->record('guild_event.signup', $event, $event->getId(), $member->getCharacterName().' hat die Terminanmeldung aktualisiert.', ['response' => $response, 'role' => $role]);

            return $response;
        });

        $this->addFlash('success', $response === GuildEventSignup::WAITLIST ? 'Der Termin ist voll. Du stehst auf der Warteliste.' : 'Deine Anmeldung wurde gespeichert.');

        return $this->redirectToRoute('app_guild_portal_show', ['id' => $guild->getId()]);
    }

    /** @param list<GuildMember> $characters
     * @return list<GuildEvent>
     */
    private function visibleEvents(Guild $guild, array $characters): array
    {
        return array_values(array_filter($this->events->upcomingForGuild($guild), fn (GuildEvent $event): bool => $this->isVisibleToCharacters($event, $characters)));
    }

    /** @param list<GuildMember> $characters */
    private function isVisibleToCharacters(GuildEvent $event, array $characters): bool
    {
        $team = $event->getTeam();
        if (!$team instanceof GuildTeam) { return true; }
        foreach ($characters as $character) {
            if ($team->getLeader() === $character || $team->getMembers()->contains($character)) { return true; }
        }
        return false;
    }

    private function escapeCalendar(string $value): string
    {
        return str_replace(["\\", ";", ",", "\r\n", "\r", "\n"], ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"], $value);
    }

    /** @return list<GuildMember> */
    private function characters(Guild $guild): array
    {
        $characters = $this->members->forUserAndGuild($this->currentUser(), $guild);
        if ($characters === []) { throw $this->createAccessDeniedException('Du bist dieser Gilde nicht zugeordnet.'); }
        return $characters;
    }
    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        return $user;
    }
}

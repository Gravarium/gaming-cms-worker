<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Guild;
use App\Entity\GuildAnnouncement;
use App\Entity\GuildEvent;
use App\Entity\GuildEventSignup;
use App\Entity\GuildDiscordIntegration;
use App\Entity\User;
use App\Form\GuildAnnouncementType;
use App\Form\GuildEventType;
use App\Form\GuildDiscordIntegrationType;
use App\Repository\GuildAnnouncementRepository;
use App\Repository\GuildEventRepository;
use App\Repository\GuildEventSignupRepository;
use App\Repository\GuildDiscordIntegrationRepository;
use App\Service\AuditLogger;
use App\Service\DiscordWebhookNotifier;
use App\Service\GuildNotifier;
use App\Service\SensitiveDataCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/guild/{guild}/collaboration')]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminGuildCollaborationController extends AbstractController
{
    public function __construct(
        private readonly GuildEventRepository $events,
        private readonly GuildEventSignupRepository $signups,
        private readonly GuildAnnouncementRepository $announcements,
        private readonly GuildDiscordIntegrationRepository $discordIntegrations,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $audit,
        private readonly GuildNotifier $notifier,
        private readonly DiscordWebhookNotifier $discordNotifier,
        private readonly SensitiveDataCipher $cipher,
    ) {}

    #[Route('', name: 'app_admin_guild_collaboration', methods: ['GET'])]
    public function index(Guild $guild): Response
    {
        return $this->render('admin/gaming/collaboration/index.html.twig', [
            'guild' => $guild,
            'events' => $this->events->findBy(['guild' => $guild], ['startsAt' => 'DESC']),
            'announcements' => $this->announcements->forGuild($guild),
            'signupRepository' => $this->signups,
            'discordIntegration' => $this->discordIntegrations->forGuild($guild),
        ]);
    }

    #[Route('/discord', name: 'app_admin_guild_discord', methods: ['GET', 'POST'])]
    public function discord(Guild $guild, Request $request): Response
    {
        $integration = $this->discordIntegrations->forGuild($guild) ?? (new GuildDiscordIntegration())->setGuild($guild);
        $form = $this->createForm(GuildDiscordIntegrationType::class, $integration, ['has_webhook' => $integration->hasWebhook()])->handleRequest($request);
        if ($form->isSubmitted()) {
            $webhookUrl = trim((string) $form->get('webhookUrl')->getData());
            if ($webhookUrl !== '') {
                $integration->setEncryptedWebhookUrl($this->cipher->encrypt($webhookUrl));
            } elseif (!$integration->hasWebhook()) {
                $form->get('webhookUrl')->addError(new FormError('Bitte zuerst eine Discord-Webhook-Adresse eintragen.'));
            }

            if ($form->isValid()) {
                if ($integration->getId() === null) { $this->entityManager->persist($integration); }
                $this->audit->record('guild_discord.update', $integration, $integration->getId(), 'Discord-Anbindung aktualisiert.', ['guild' => $guild->getName(), 'enabled' => $integration->isEnabled()]);
                $this->entityManager->flush();
                $this->addFlash('success', 'Die Discord-Einstellungen wurden gespeichert.');
                return $this->redirectToRoute('app_admin_guild_discord', ['guild' => $guild->getId()]);
            }
        }

        return $this->render('admin/gaming/discord.html.twig', ['guild' => $guild, 'integration' => $integration, 'form' => $form]);
    }

    #[Route('/discord/test', name: 'app_admin_guild_discord_test', methods: ['POST'])]
    public function testDiscord(Guild $guild, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('discord-test-'.$guild->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $success = $this->discordNotifier->sendTest($guild);
        $this->audit->record('guild_discord.test', $guild, $guild->getId(), $success ? 'Discord-Test erfolgreich.' : 'Discord-Test fehlgeschlagen.');
        $this->entityManager->flush();
        $this->addFlash($success ? 'success' : 'error', $success ? 'Die Testnachricht wurde gesendet.' : 'Die Testnachricht konnte nicht gesendet werden.');
        return $this->redirectToRoute('app_admin_guild_discord', ['guild' => $guild->getId()]);
    }

    #[Route('/event/new', name: 'app_admin_guild_event_new', methods: ['GET', 'POST'])]
    public function newEvent(Guild $guild, Request $request): Response
    {
        $event = (new GuildEvent())->setGuild($guild)->setCreatedBy($this->currentUser());
        return $this->eventForm($guild, $event, $request, 'Termin oder Raid erstellen');
    }

    #[Route('/event/{event}/edit', name: 'app_admin_guild_event_edit', requirements: ['event' => '\d+'], methods: ['GET', 'POST'])]
    public function editEvent(Guild $guild, GuildEvent $event, Request $request): Response
    {
        $this->ensureGuild($guild, $event->getGuild());
        return $this->eventForm($guild, $event, $request, 'Termin bearbeiten');
    }

    #[Route('/event/{event}/delete', name: 'app_admin_guild_event_delete', requirements: ['event' => '\d+'], methods: ['POST'])]
    public function deleteEvent(Guild $guild, GuildEvent $event, Request $request): Response
    {
        $this->ensureGuild($guild, $event->getGuild());
        if (!$this->isCsrfTokenValid('delete-event-'.$event->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $this->audit->record('guild_event.delete', $event, $event->getId(), 'Termin gelöscht: '.$event->getTitle(), ['guild' => $guild->getName()]);
        $this->entityManager->remove($event); $this->entityManager->flush();
        return $this->redirectToRoute('app_admin_guild_collaboration', ['guild' => $guild->getId()]);
    }

    #[Route('/event/{event}/attendance/{signup}', name: 'app_admin_guild_event_attendance', requirements: ['event' => '\\d+', 'signup' => '\\d+'], methods: ['POST'])]
    public function attendance(Guild $guild, GuildEvent $event, GuildEventSignup $signup, Request $request): Response
    {
        $this->ensureGuild($guild, $event->getGuild());
        if ($signup->getEvent()?->getId() !== $event->getId()) { throw $this->createNotFoundException(); }
        if (!$this->isCsrfTokenValid('attendance-'.$signup->getId(), $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $state = $request->request->getString('attendance');
        $signup->markAttendance($state, $this->currentUser());
        $this->audit->record('guild_event.attendance', $event, $event->getId(), 'Anwesenheit aktualisiert.', ['member' => $signup->getMember()?->getCharacterName(), 'attendance' => $state]);
        $this->entityManager->flush();
        $this->addFlash('success', 'Anwesenheit wurde gespeichert.');
        return $this->redirectToRoute('app_admin_guild_collaboration', ['guild' => $guild->getId()]);
    }

    #[Route('/announcement/new', name: 'app_admin_guild_announcement_new', methods: ['GET', 'POST'])]
    public function newAnnouncement(Guild $guild, Request $request): Response
    {
        $announcement = (new GuildAnnouncement())->setGuild($guild)->setAuthor($this->currentUser());
        return $this->announcementForm($guild, $announcement, $request, 'Interne Mitteilung erstellen');
    }

    #[Route('/announcement/{announcement}/edit', name: 'app_admin_guild_announcement_edit', requirements: ['announcement' => '\d+'], methods: ['GET', 'POST'])]
    public function editAnnouncement(Guild $guild, GuildAnnouncement $announcement, Request $request): Response
    {
        $this->ensureGuild($guild, $announcement->getGuild());
        return $this->announcementForm($guild, $announcement, $request, 'Mitteilung bearbeiten');
    }

    #[Route('/announcement/{announcement}/delete', name: 'app_admin_guild_announcement_delete', requirements: ['announcement' => '\d+'], methods: ['POST'])]
    public function deleteAnnouncement(Guild $guild, GuildAnnouncement $announcement, Request $request): Response
    {
        $this->ensureGuild($guild, $announcement->getGuild());
        if (!$this->isCsrfTokenValid('delete-announcement-'.$announcement->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $this->audit->record('guild_announcement.delete', $announcement, $announcement->getId(), 'Mitteilung gelöscht: '.$announcement->getTitle(), ['guild' => $guild->getName()]);
        $this->entityManager->remove($announcement); $this->entityManager->flush();
        return $this->redirectToRoute('app_admin_guild_collaboration', ['guild' => $guild->getId()]);
    }

    private function eventForm(Guild $guild, GuildEvent $event, Request $request, string $heading): Response
    {
        $form = $this->createForm(GuildEventType::class, $event, ['guild' => $guild])->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $isNew = $event->getId() === null;
            if ($isNew) { $this->entityManager->persist($event); }
            $this->audit->record($isNew ? 'guild_event.create' : 'guild_event.update', $event, $event->getId(), ($isNew ? 'Termin erstellt: ' : 'Termin aktualisiert: ').$event->getTitle(), ['guild' => $guild->getName()]);
            if ($isNew) { $this->notifier->notify($guild, 'guild_event', 'Neuer Termin: '.$event->getTitle(), 'Beginn: '.$event->getStartsAt()->format('d.m.Y H:i').' Uhr'); }
            $this->entityManager->flush();
            return $this->redirectToRoute('app_admin_guild_collaboration', ['guild' => $guild->getId()]);
        }
        return $this->formPage($guild, $form, $heading);
    }

    private function announcementForm(Guild $guild, GuildAnnouncement $announcement, Request $request, string $heading): Response
    {
        $form = $this->createForm(GuildAnnouncementType::class, $announcement)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $isNew = $announcement->getId() === null;
            if ($isNew) { $this->entityManager->persist($announcement); }
            $this->audit->record($isNew ? 'guild_announcement.create' : 'guild_announcement.update', $announcement, $announcement->getId(), ($isNew ? 'Mitteilung erstellt: ' : 'Mitteilung aktualisiert: ').$announcement->getTitle(), ['guild' => $guild->getName()]);
            if ($isNew) { $this->notifier->notify($guild, 'guild_announcement', 'Neue Mitteilung: '.$announcement->getTitle(), mb_strimwidth($announcement->getBody(), 0, 220, '…')); }
            $this->entityManager->flush();
            return $this->redirectToRoute('app_admin_guild_collaboration', ['guild' => $guild->getId()]);
        }
        return $this->formPage($guild, $form, $heading);
    }

    /** @param FormInterface<mixed> $form */
    private function formPage(Guild $guild, FormInterface $form, string $heading): Response
    {
        return $this->render('admin/gaming/collaboration/form.html.twig', ['guild' => $guild, 'form' => $form, 'heading' => $heading]);
    }

    private function currentUser(): ?User { $user = $this->getUser(); return $user instanceof User ? $user : null; }
    private function ensureGuild(Guild $expected, ?Guild $actual): void { if ($actual?->getId() !== $expected->getId()) { throw $this->createNotFoundException(); } }
}

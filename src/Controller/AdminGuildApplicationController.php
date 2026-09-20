<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GuildApplication;
use App\Entity\GuildMember;
use App\Entity\User;
use App\Repository\GuildApplicationRepository;
use App\Repository\GuildRankRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/applications')]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminGuildApplicationController extends AbstractController
{
    public function __construct(
        private readonly GuildApplicationRepository $applications,
        private readonly GuildRankRepository $ranks,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $audit,
    ) {}

    #[Route('', name: 'app_admin_guild_application_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/gaming/application/index.html.twig', ['applications' => $this->applications->findBy([], ['createdAt' => 'DESC'])]);
    }

    #[Route('/{id}/review', name: 'app_admin_guild_application_review', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function review(GuildApplication $application, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('review-application-'.$application->getId(), $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
            $user = $this->getUser();
            if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
            $application->assignTo($request->request->getBoolean('release') ? null : $user)
                ->setInternalNotes($request->request->getString('internal_notes'));
            $this->audit->record('guild_application.review', $application, $application->getId(), 'Bewerbungsprüfung aktualisiert.', ['guild' => $application->getGuild()?->getName(), 'assigned' => $application->getAssignedTo()?->getEmail()]);
            $this->entityManager->flush();
            $this->addFlash('success', 'Bearbeitung und interne Notizen wurden gespeichert.');
            return $this->redirectToRoute('app_admin_guild_application_review', ['id' => $application->getId()]);
        }

        return $this->render('admin/gaming/application/review.html.twig', ['application' => $application]);
    }

    #[Route('/{id}/{decision}', name: 'app_admin_guild_application_decide', requirements: ['id' => '\d+', 'decision' => 'accept|reject'], methods: ['POST'])]
    public function decide(GuildApplication $application, string $decision, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('application-'.$application->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        if (!$application->isOpen()) { throw $this->createNotFoundException('Diese Bewerbung wurde bereits abschließend entschieden.'); }

        if ($decision === 'accept') {
            $application->accept();
            if ($application->getConvertedMember() === null) {
                /** @var \App\Entity\Guild $guild */
                $guild = $application->getGuild();
                $rank = $this->ranks->defaultForGuild($guild);
                $member = (new GuildMember())
                    ->setGuild($guild)
                    ->setUser($this->users->findOneBy(['email' => $application->getEmail()]))
                    ->setCharacterName($application->getCharacterName())
                    ->setCharacterClass($application->getCharacterClass())
                    ->setPlayerName($application->getApplicantName())
                    ->setRank($rank)
                    ->setRankName($rank?->getName() ?? 'Mitglied')
                    ->setActive(true);
                $this->entityManager->persist($member);
                $application->setConvertedMember($member);
            }
        } else {
            $application->reject();
        }

        $this->audit->record('guild_application.'.$decision, $application, $application->getId(), $decision === 'accept' ? 'Bewerbung angenommen.' : 'Bewerbung abgelehnt.', ['guild' => $application->getGuild()?->getName()]);
        $this->entityManager->flush();
        $this->addFlash('success', $decision === 'accept' ? 'Bewerbung angenommen und Mitglied angelegt.' : 'Die Bewerbung wurde abgelehnt.');
        return $this->redirectToRoute('app_admin_guild_application_index');
    }
}

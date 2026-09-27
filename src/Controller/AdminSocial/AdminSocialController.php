<?php

declare(strict_types=1);

namespace App\Controller\AdminSocial;

use App\Entity\Social\SocialReport;
use App\Entity\User;
use App\Repository\Social\SocialReportRepository;
use App\Social\SocialModuleAvailability;
use App\Social\SocialModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/social')]
#[IsGranted('ROLE_ADMIN')]
final class AdminSocialController extends AbstractController
{
    public function __construct(
        private readonly SocialModuleAvailability $availability,
        private readonly SocialReportRepository $reports,
        private readonly SocialModerationService $moderation,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/reports', name: 'app_admin_social_reports', methods: ['GET'])]
    public function reports(): Response
    {
        $this->assertEnabled();

        return $this->render('admin/social/reports.html.twig', ['reports' => $this->reports->openQueue()]);
    }

    #[Route('/reports/{id}/{outcome}', name: 'app_admin_social_report_decide', requirements: ['id' => '\\d+', 'outcome' => 'uphold|reject'], methods: ['POST'])]
    public function decide(int $id, string $outcome, Request $request): Response
    {
        $this->assertEnabled();
        if (!$this->isCsrfTokenValid('social-moderation-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $report = $this->reports->find($id);
        $moderator = $this->getUser();
        if (!$report instanceof SocialReport || !$moderator instanceof User) {
            throw $this->createNotFoundException();
        }

        $this->moderation->decide($moderator, $report, $outcome === 'uphold', $request->request->getString('reason'));
        $this->entityManager->flush();
        $this->addFlash('success', 'Meldung entschieden.');

        return $this->redirectToRoute('app_admin_social_reports');
    }

    private function assertEnabled(): void
    {
        if (!$this->availability->enabled()) {
            throw $this->createNotFoundException();
        }
    }
}

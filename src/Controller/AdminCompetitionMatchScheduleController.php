<?php

declare(strict_types=1);

namespace App\Controller;

use App\CompetitionSchedule\AdminMatchSchedule;
use App\Entity\Competition\Competition;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/competitions/{id}/schedule', name: 'app_admin_competition_schedule_board', requirements: ['id' => '\\d+'], methods: ['GET'])]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminCompetitionMatchScheduleController extends AbstractController
{
    public function __construct(
        private readonly AdminMatchSchedule $schedule,
        private readonly CmsModuleManager $modules,
    ) {
    }

    public function __invoke(Competition $competition): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('admin/competition_match_schedule/index.html.twig', [
            'competition' => $competition,
            'matches' => $this->schedule->forCompetition($competition),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}

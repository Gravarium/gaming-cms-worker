<?php

declare(strict_types=1);

namespace App\Controller;

use App\CompetitionDisputeQueue\OpenDisputeQueue;
use App\Module\CmsModuleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/competitions/disputes', name: 'app_admin_competition_dispute_queue', methods: ['GET'])]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminCompetitionDisputeQueueController extends AbstractController
{
    public function __construct(
        private readonly CmsModuleManager $modules,
        private readonly OpenDisputeQueue $queue,
    ) {
    }

    public function __invoke(): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }

        $response = $this->render('admin/competition_dispute_queue/index.html.twig', [
            'disputes' => $this->queue->recent(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}

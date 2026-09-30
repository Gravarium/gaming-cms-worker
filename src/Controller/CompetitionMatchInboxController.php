<?php

declare(strict_types=1);

namespace App\Controller;

use App\CompetitionMatchInbox\CaptainMatchInbox;
use App\Entity\User;
use App\Module\CmsModuleManager;
use App\Widget\MyCompetitionsWidgetProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CompetitionMatchInboxController extends AbstractController
{
    public function __construct(
        private readonly CaptainMatchInbox $inbox,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route('/account/competitions/matches', name: 'app_competition_match_inbox', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->modules->isEnabled('gaming')) {
            throw $this->createNotFoundException();
        }
        $captain = $this->getUser();
        if (!$captain instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $request->attributes->set(MyCompetitionsWidgetProvider::PERSONALIZED_ATTRIBUTE, true);

        $response = $this->render('competition_match_inbox/index.html.twig', [
            'matches' => $this->inbox->forCaptain($captain),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}

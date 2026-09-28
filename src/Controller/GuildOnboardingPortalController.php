<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Guild\GuildOnboardingTask;
use App\Entity\User;
use App\Repository\GuildOnboardingPortalRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[Route('/guild-area/onboarding')]
#[IsGranted('ROLE_USER')]
final class GuildOnboardingPortalController extends AbstractController
{
    public function __construct(
        private readonly GuildOnboardingPortalRepository $tasks,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_guild_onboarding_portal', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->currentUser();
        $requestedPage = $this->requestedPage($request);
        $total = $this->tasks->countForUser($user);
        $pageCount = max(1, (int) ceil($total / GuildOnboardingPortalRepository::PAGE_SIZE));
        $page = min($requestedPage, $pageCount);
        $response = $this->render('guild_portal/onboarding.html.twig', [
            'tasks' => $this->tasks->pageForUser($user, $page),
            'page' => $page,
            'pageCount' => $pageCount,
            'total' => $total,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }

    #[Route('/task/{taskId}/complete', name: 'app_guild_onboarding_task_complete', requirements: ['taskId' => '\\d+'], methods: ['POST'])]
    public function complete(int $taskId, Request $request): Response
    {
        $user = $this->currentUser();
        if (!$this->isCsrfTokenValid(
            'member-onboarding-complete-'.$taskId,
            (string) $request->request->get('_token'),
        )) {
            throw $this->createAccessDeniedException();
        }

        $completedNow = $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $entityManager) use ($taskId, $user): bool {
                $task = $this->tasks->taskForUser($taskId, $user);
                if (!$task instanceof GuildOnboardingTask) {
                    throw $this->createNotFoundException();
                }

                $entityManager->refresh($task, LockMode::PESSIMISTIC_WRITE);
                $task = $this->tasks->taskForUser($taskId, $user);
                if (!$task instanceof GuildOnboardingTask) {
                    throw $this->createNotFoundException();
                }
                if ($task->isCompleted()) {
                    return false;
                }

                $task->complete($user);

                return true;
            },
        );

        $this->addFlash(
            $completedNow ? 'success' : 'notice',
            $completedNow
                ? 'Die Aufgabe wurde als erledigt markiert.'
                : 'Die Aufgabe war bereits erledigt; der Nachweis blieb unverändert.',
        );

        return $this->redirectToRoute('app_guild_onboarding_portal');
    }

    private function requestedPage(Request $request): int
    {
        $query = $request->query->all();
        $raw = $query['page'] ?? null;
        if ($raw === null) {
            return 1;
        }
        if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,6}$/D', $raw) !== 1) {
            throw new BadRequestHttpException('Ungültige Seitennummer.');
        }

        $page = filter_var($raw, FILTER_VALIDATE_INT);
        if (!is_int($page) || $page > 1_000_000) {
            throw new BadRequestHttpException('Ungültige Seitennummer.');
        }

        return $page;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}

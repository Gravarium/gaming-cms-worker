<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Entity\Guild\GuildOnboardingTask;
use App\Entity\User;
use App\Form\GuildOnboardingTaskInput;
use App\Form\GuildOnboardingTaskType;
use App\Repository\GuildMemberRepository;
use App\Repository\GuildOnboardingTaskReadRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/guild/{guild}/onboarding')]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminGuildOnboardingController extends AbstractController
{
    public function __construct(
        private readonly GuildMemberRepository $members,
        private readonly GuildOnboardingTaskReadRepository $tasks,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_admin_guild_onboarding_index', methods: ['GET'])]
    public function index(Guild $guild): Response
    {
        return $this->render('admin/gaming/onboarding/index.html.twig', [
            'guild' => $guild,
            'tasks' => $this->tasks->forGuild($guild),
        ]);
    }

    #[Route('/new', name: 'app_admin_guild_onboarding_new', methods: ['GET', 'POST'])]
    public function new(Guild $guild, Request $request): Response
    {
        $input = new GuildOnboardingTaskInput();
        $form = $this->createForm(GuildOnboardingTaskType::class, $input, [
            'guild' => $guild,
            'csrf_token_id' => 'create-onboarding-task-'.$guild->getId(),
        ])->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $member = $input->getMember();
            if (!$member instanceof GuildMember || $member->getGuild()?->getId() !== $guild->getId() || !$member->isActive()) {
                $form->get('member')->addError(new FormError('Wähle ein aktives Mitglied dieser Gilde aus.'));
            } else {
                try {
                    $task = new GuildOnboardingTask($guild, $member, $input->getLabel());
                    $this->entityManager->persist($task);
                    $this->entityManager->flush();
                    $this->addFlash('success', 'Die Onboarding-Aufgabe wurde angelegt.');

                    return $this->redirectToRoute('app_admin_guild_onboarding_index', ['guild' => $guild->getId()]);
                } catch (\DomainException|\InvalidArgumentException $exception) {
                    $form->addError(new FormError($exception->getMessage()));
                }
            }
        }

        return $this->render('admin/gaming/onboarding/form.html.twig', [
            'guild' => $guild,
            'form' => $form,
            'hasActiveMembers' => $this->members->activeForGuild($guild) !== [],
        ], $form->isSubmitted() && !$form->isValid() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null);
    }

    #[Route('/task/{taskId}/complete', name: 'app_admin_guild_onboarding_complete', requirements: ['taskId' => '\d+'], methods: ['POST'])]
    public function complete(Guild $guild, int $taskId, Request $request): Response
    {
        $task = $this->taskForGuild($guild, $taskId);
        if (!$this->isCsrfTokenValid(
            'complete-onboarding-task-'.$guild->getId().'-'.$taskId,
            (string) $request->request->get('_token'),
        )) {
            throw $this->createAccessDeniedException();
        }

        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $completedNow = $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $entityManager) use ($task, $actor): bool {
                $entityManager->refresh($task, LockMode::PESSIMISTIC_WRITE);
                if ($task->isCompleted()) {
                    return false;
                }

                $task->complete($actor);

                return true;
            },
        );

        $this->addFlash(
            $completedNow ? 'success' : 'notice',
            $completedNow ? 'Die Aufgabe wurde als erledigt markiert.' : 'Die Aufgabe war bereits erledigt; der Nachweis blieb unverändert.',
        );

        return $this->redirectToRoute('app_admin_guild_onboarding_index', ['guild' => $guild->getId()]);
    }

    #[Route('/task/{taskId}/delete', name: 'app_admin_guild_onboarding_delete', requirements: ['taskId' => '\d+'], methods: ['POST'])]
    public function delete(Guild $guild, int $taskId, Request $request): Response
    {
        $task = $this->taskForGuild($guild, $taskId);
        if (!$this->isCsrfTokenValid(
            'delete-onboarding-task-'.$guild->getId().'-'.$taskId,
            (string) $request->request->get('_token'),
        )) {
            throw $this->createAccessDeniedException();
        }

        $deleted = $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $entityManager) use ($task): bool {
                $entityManager->refresh($task, LockMode::PESSIMISTIC_WRITE);
                if ($task->isCompleted()) {
                    return false;
                }

                $entityManager->remove($task);

                return true;
            },
        );

        $this->addFlash(
            $deleted ? 'success' : 'error',
            $deleted ? 'Die offene Aufgabe wurde gelöscht.' : 'Erledigte Aufgaben bleiben als Nachweis erhalten.',
        );

        return $this->redirectToRoute('app_admin_guild_onboarding_index', ['guild' => $guild->getId()]);
    }

    private function taskForGuild(Guild $guild, int $taskId): GuildOnboardingTask
    {
        $task = $this->entityManager->getRepository(GuildOnboardingTask::class)->findOneBy([
            'id' => $taskId,
            'guild' => $guild,
        ]);
        if (!$task instanceof GuildOnboardingTask) {
            throw $this->createNotFoundException();
        }

        return $task;
    }
}

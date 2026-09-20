<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Guild;
use App\Entity\GuildApplicationQuestion;
use App\Entity\GuildRank;
use App\Entity\GuildTeam;
use App\Form\GuildApplicationQuestionType;
use App\Form\GuildRankType;
use App\Form\GuildTeamType;
use App\Repository\GuildApplicationQuestionRepository;
use App\Repository\GuildRankRepository;
use App\Repository\GuildTeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/guild/{guild}/structure')]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminGuildStructureController extends AbstractController
{
    public function __construct(
        private readonly GuildRankRepository $ranks,
        private readonly GuildApplicationQuestionRepository $questions,
        private readonly GuildTeamRepository $teams,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: 'app_admin_guild_structure', methods: ['GET'])]
    public function index(Guild $guild): Response
    {
        return $this->render('admin/gaming/structure/index.html.twig', [
            'guild' => $guild,
            'ranks' => $this->ranks->findBy(['guild' => $guild], ['position' => 'ASC']),
            'questions' => $this->questions->findBy(['guild' => $guild], ['position' => 'ASC']),
            'teams' => $this->teams->forGuild($guild),
        ]);
    }

    #[Route('/team/new', name: 'app_admin_guild_team_new', methods: ['GET', 'POST'])]
    public function newTeam(Guild $guild, Request $request): Response
    {
        return $this->teamForm($guild, (new GuildTeam())->setGuild($guild), $request, 'Team anlegen');
    }

    #[Route('/team/{team}/edit', name: 'app_admin_guild_team_edit', requirements: ['team' => '\\d+'], methods: ['GET', 'POST'])]
    public function editTeam(Guild $guild, GuildTeam $team, Request $request): Response
    {
        $this->ensureGuild($guild, $team->getGuild());
        return $this->teamForm($guild, $team, $request, 'Team bearbeiten');
    }

    #[Route('/team/{team}/delete', name: 'app_admin_guild_team_delete', requirements: ['team' => '\\d+'], methods: ['POST'])]
    public function deleteTeam(Guild $guild, GuildTeam $team, Request $request): Response
    {
        $this->ensureGuild($guild, $team->getGuild());
        if (!$this->isCsrfTokenValid('delete-team-'.$team->getId(), $request->request->getString('_token'))) { throw $this->createAccessDeniedException(); }
        $this->entityManager->remove($team);
        $this->entityManager->flush();
        return $this->redirectToRoute('app_admin_guild_structure', ['guild' => $guild->getId()]);
    }

    #[Route('/rank/new', name: 'app_admin_guild_rank_new', methods: ['GET', 'POST'])]
    public function newRank(Guild $guild, Request $request): Response
    {
        return $this->rankForm($guild, (new GuildRank())->setGuild($guild), $request, 'Gildenrang anlegen');
    }

    #[Route('/rank/{rank}/edit', name: 'app_admin_guild_rank_edit', requirements: ['rank' => '\d+'], methods: ['GET', 'POST'])]
    public function editRank(Guild $guild, GuildRank $rank, Request $request): Response
    {
        $this->ensureGuild($guild, $rank->getGuild());
        return $this->rankForm($guild, $rank, $request, 'Gildenrang bearbeiten');
    }

    #[Route('/rank/{rank}/delete', name: 'app_admin_guild_rank_delete', requirements: ['rank' => '\d+'], methods: ['POST'])]
    public function deleteRank(Guild $guild, GuildRank $rank, Request $request): Response
    {
        $this->ensureGuild($guild, $rank->getGuild());
        if (!$this->isCsrfTokenValid('delete-rank-'.$rank->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $this->entityManager->remove($rank);
        $this->entityManager->flush();
        return $this->redirectToRoute('app_admin_guild_structure', ['guild' => $guild->getId()]);
    }

    #[Route('/question/new', name: 'app_admin_guild_question_new', methods: ['GET', 'POST'])]
    public function newQuestion(Guild $guild, Request $request): Response
    {
        return $this->questionForm($guild, (new GuildApplicationQuestion())->setGuild($guild), $request, 'Bewerbungsfrage anlegen');
    }

    #[Route('/question/{question}/edit', name: 'app_admin_guild_question_edit', requirements: ['question' => '\d+'], methods: ['GET', 'POST'])]
    public function editQuestion(Guild $guild, GuildApplicationQuestion $question, Request $request): Response
    {
        $this->ensureGuild($guild, $question->getGuild());
        return $this->questionForm($guild, $question, $request, 'Bewerbungsfrage bearbeiten');
    }

    #[Route('/question/{question}/delete', name: 'app_admin_guild_question_delete', requirements: ['question' => '\d+'], methods: ['POST'])]
    public function deleteQuestion(Guild $guild, GuildApplicationQuestion $question, Request $request): Response
    {
        $this->ensureGuild($guild, $question->getGuild());
        if (!$this->isCsrfTokenValid('delete-question-'.$question->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $this->entityManager->remove($question);
        $this->entityManager->flush();
        return $this->redirectToRoute('app_admin_guild_structure', ['guild' => $guild->getId()]);
    }

    private function teamForm(Guild $guild, GuildTeam $team, Request $request, string $heading): Response
    {
        $form = $this->createForm(GuildTeamType::class, $team, ['guild' => $guild])->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($team->getLeader() !== null && !$team->getMembers()->contains($team->getLeader())) { $team->addMember($team->getLeader()); }
            if ($team->getId() === null) { $this->entityManager->persist($team); }
            $this->entityManager->flush();
            return $this->redirectToRoute('app_admin_guild_structure', ['guild' => $guild->getId()]);
        }
        return $this->formPage($guild, $form, $heading);
    }

    private function rankForm(Guild $guild, GuildRank $rank, Request $request, string $heading): Response
    {
        $form = $this->createForm(GuildRankType::class, $rank)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($rank->isDefaultRank()) {
                foreach ($this->ranks->findBy(['guild' => $guild, 'defaultRank' => true]) as $other) {
                    if ($other !== $rank) { $other->setDefaultRank(false); }
                }
            }
            if ($rank->getId() === null) { $this->entityManager->persist($rank); }
            $this->entityManager->flush();
            return $this->redirectToRoute('app_admin_guild_structure', ['guild' => $guild->getId()]);
        }
        return $this->formPage($guild, $form, $heading);
    }

    private function questionForm(Guild $guild, GuildApplicationQuestion $question, Request $request, string $heading): Response
    {
        $form = $this->createForm(GuildApplicationQuestionType::class, $question)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($question->getId() === null) { $this->entityManager->persist($question); }
            $this->entityManager->flush();
            return $this->redirectToRoute('app_admin_guild_structure', ['guild' => $guild->getId()]);
        }
        return $this->formPage($guild, $form, $heading);
    }

    /** @param FormInterface<mixed> $form */
    private function formPage(Guild $guild, FormInterface $form, string $heading): Response
    {
        return $this->render('admin/gaming/structure/form.html.twig', ['guild' => $guild, 'form' => $form, 'heading' => $heading]);
    }

    private function ensureGuild(Guild $expected, ?Guild $actual): void
    {
        if ($actual?->getId() !== $expected->getId()) { throw $this->createNotFoundException(); }
    }
}

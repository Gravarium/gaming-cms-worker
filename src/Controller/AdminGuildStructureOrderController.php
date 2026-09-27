<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Guild;
use App\Entity\GuildApplicationQuestion;
use App\Entity\GuildRank;
use App\Repository\GuildApplicationQuestionRepository;
use App\Repository\GuildRankRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/gaming/guild/{guild}/structure')]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminGuildStructureOrderController extends AbstractController
{
    public function __construct(
        private readonly GuildRankRepository $ranks,
        private readonly GuildApplicationQuestionRepository $questions,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $audit,
    ) {
    }

    #[Route('/rank/{rank}/move/{direction}', name: 'app_admin_guild_rank_move', requirements: ['rank' => '\\d+', 'direction' => 'up|down'], methods: ['POST'])]
    public function moveRank(Guild $guild, GuildRank $rank, string $direction, Request $request): Response
    {
        $this->ensureGuild($guild, $rank->getGuild());

        if (!$this->isCsrfTokenValid('guild-rank-move-'.$rank->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $moved = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($guild, $rank, $direction): bool {
            /** @var list<GuildRank> $ranks */
            $ranks = $this->ranks->findBy(['guild' => $guild], ['position' => 'ASC', 'id' => 'ASC']);
            $index = array_search($rank, $ranks, true);

            if (!is_int($index)) {
                return false;
            }

            $neighbor = $index + ($direction === 'up' ? -1 : 1);
            if (!isset($ranks[$neighbor])) {
                return false;
            }

            [$ranks[$index], $ranks[$neighbor]] = [$ranks[$neighbor], $ranks[$index]];
            foreach ($ranks as $position => $item) {
                $item->setPosition($position);
            }

            $this->audit->record(
                'guild_structure.rank.reordered',
                $rank,
                $rank->getId(),
                'Gildenrang neu sortiert.',
                ['guildId' => $guild->getId(), 'direction' => $direction],
            );

            return true;
        });

        if ($moved) {
            $this->addFlash('success', 'Die Rangfolge wurde aktualisiert.');
        }

        return $this->redirectToRoute('app_admin_guild_structure', ['guild' => $guild->getId()]);
    }

    #[Route('/question/{question}/move/{direction}', name: 'app_admin_guild_question_move', requirements: ['question' => '\\d+', 'direction' => 'up|down'], methods: ['POST'])]
    public function moveQuestion(Guild $guild, GuildApplicationQuestion $question, string $direction, Request $request): Response
    {
        $this->ensureGuild($guild, $question->getGuild());

        if (!$this->isCsrfTokenValid('guild-question-move-'.$question->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $moved = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($guild, $question, $direction): bool {
            /** @var list<GuildApplicationQuestion> $questions */
            $questions = $this->questions->findBy(['guild' => $guild], ['position' => 'ASC', 'id' => 'ASC']);
            $index = array_search($question, $questions, true);

            if (!is_int($index)) {
                return false;
            }

            $neighbor = $index + ($direction === 'up' ? -1 : 1);
            if (!isset($questions[$neighbor])) {
                return false;
            }

            [$questions[$index], $questions[$neighbor]] = [$questions[$neighbor], $questions[$index]];
            foreach ($questions as $position => $item) {
                $item->setPosition($position);
            }

            $this->audit->record(
                'guild_structure.question.reordered',
                $question,
                $question->getId(),
                'Gildenbewerbungsfrage neu sortiert.',
                ['guildId' => $guild->getId(), 'direction' => $direction],
            );

            return true;
        });

        if ($moved) {
            $this->addFlash('success', 'Die Reihenfolge der Bewerbungsfragen wurde aktualisiert.');
        }

        return $this->redirectToRoute('app_admin_guild_structure', ['guild' => $guild->getId()]);
    }

    private function ensureGuild(Guild $expected, ?Guild $actual): void
    {
        if ($actual?->getId() !== $expected->getId()) {
            throw $this->createNotFoundException();
        }
    }
}

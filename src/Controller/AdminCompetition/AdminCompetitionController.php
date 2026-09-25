<?php

declare(strict_types=1);

namespace App\Controller\AdminCompetition;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionDispute;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionSeason;
use App\Entity\User;
use App\Form\Competition\CompetitionType;
use App\Gaming\Competition\CompetitionBracket;
use App\Module\CmsModuleManager;
use App\Repository\Competition\CompetitionMatchRepository;
use App\Repository\Competition\CompetitionParticipantRepository;
use App\Repository\Competition\CompetitionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/gaming/competitions')]
#[IsGranted('CMS_GAMING_MANAGE')]
final class AdminCompetitionController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitions,
        private readonly CompetitionParticipantRepository $participants,
        private readonly CompetitionMatchRepository $matches,
        private readonly CompetitionBracket $brackets,
        private readonly CmsModuleManager $modules,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
    ) {
    }

    #[Route('', name: 'app_admin_competition_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->assertAvailable();
        return $this->render('admin/competition/index.html.twig', ['competitions' => $this->competitions->recentForAdmin()]);
    }

    #[Route('/new', name: 'app_admin_competition_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->assertAvailable();
        $competition = new Competition();
        $form = $this->createForm(CompetitionType::class, $competition)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $competition->setSlug($this->slug($competition->getName()));
            $competition->setCreatedBy($this->currentUser());
            $this->entityManager->persist($competition);
            $this->entityManager->flush();
            $this->addFlash('success', 'Die Competition wurde angelegt.');
            return $this->redirectToRoute('app_admin_competition_index');
        }
        return $this->render('admin/competition/form.html.twig', ['form' => $form, 'heading' => 'Competition anlegen']);
    }

    #[Route('/{id}/edit', name: 'app_admin_competition_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Competition $competition, Request $request): Response
    {
        $this->assertAvailable();
        $form = $this->createForm(CompetitionType::class, $competition)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $competition->setSlug($this->slug($competition->getName(), $competition->getId()));
            $this->entityManager->flush();
            $this->addFlash('success', 'Die Competition wurde gespeichert.');
            return $this->redirectToRoute('app_admin_competition_index');
        }
        return $this->render('admin/competition/form.html.twig', ['form' => $form, 'heading' => 'Competition bearbeiten', 'competition' => $competition]);
    }

    #[Route('/{id}/open', name: 'app_admin_competition_open', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function open(Competition $competition, Request $request): Response
    {
        $this->assertAvailable();
        if (!$this->isCsrfTokenValid('competition-open-'.$competition->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $competition->open();
        $this->entityManager->flush();
        return $this->redirectToRoute('app_admin_competition_index');
    }

    #[Route('/{id}/seed', name: 'app_admin_competition_seed', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function seed(Competition $competition, Request $request): Response
    {
        $this->assertAvailable();
        if (!$this->isCsrfTokenValid('competition-seed-'.$competition->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        if ($this->matches->forCompetition($competition) !== []) { throw $this->createNotFoundException('Für diese Competition existieren bereits Paarungen.'); }
        $active = $this->participants->checkedInFor($competition);
        $pairings = $this->brackets->initialPairings($competition, $active);
        foreach ($pairings as $pairing) {
            $match = (new CompetitionMatch())
                ->setCompetition($competition)
                ->setRoundNumber($pairing['round'])
                ->setBracket($pairing['bracket'])
                ->setSequence($pairing['sequence'])
                ->setParticipants($pairing['participantA'], $pairing['participantB']);
            $match->markReady();
            $this->entityManager->persist($match);
        }
        $competition->start();
        $this->entityManager->flush();
        $this->addFlash('success', 'Die Startpaarungen wurden erzeugt.');
        return $this->redirectToRoute('app_admin_competition_index');
    }

    #[Route('/{id}/archive', name: 'app_admin_competition_archive', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function archive(Competition $competition, Request $request): Response
    {
        $this->assertAvailable();
        if (!$this->isCsrfTokenValid('competition-archive-'.$competition->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $competition->archive();
        $this->entityManager->flush();
        return $this->redirectToRoute('app_admin_competition_index');
    }

    #[Route('/season/{season}/archive', name: 'app_admin_competition_season_archive', requirements: ['season' => '\\d+'], methods: ['POST'])]
    public function archiveSeason(CompetitionSeason $season, Request $request): Response
    {
        $this->assertAvailable();
        if (!$this->isCsrfTokenValid('competition-season-archive-'.$season->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $season->archive();
        $this->entityManager->flush();
        return $this->redirectToRoute('app_admin_competition_index');
    }

    #[Route('/{id}/match/{match}/schedule', name: 'app_admin_competition_match_schedule', requirements: ['id' => '\\d+', 'match' => '\\d+'], methods: ['POST'])]
    public function schedule(Competition $competition, CompetitionMatch $match, Request $request): Response
    {
        $this->assertAvailable();
        if ($match->getCompetition()?->getId() !== $competition->getId() || !$this->isCsrfTokenValid('competition-schedule-'.$match->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $raw = trim((string) $request->request->get('scheduled_at'));
        if ($raw === '') { throw new BadRequestHttpException('Der Spieltermin ist erforderlich.'); }
        try {
            $scheduledAt = new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new BadRequestHttpException('Der Spieltermin ist ungültig.');
        }
        if ($scheduledAt < new \DateTimeImmutable('-5 minutes')) { throw new BadRequestHttpException('Ein Spieltermin darf nicht in der Vergangenheit liegen.'); }
        $match->setScheduledAt($scheduledAt);
        $this->entityManager->flush();
        return $this->redirectToRoute('app_admin_competition_index');
    }

    #[Route('/{id}/dispute/{dispute}/decide', name: 'app_admin_competition_dispute_decide', requirements: ['id' => '\\d+', 'dispute' => '\\d+'], methods: ['POST'])]
    public function decideDispute(Competition $competition, CompetitionDispute $dispute, Request $request): Response
    {
        $this->assertAvailable();
        $match = $dispute->getMatch();
        if ($match?->getCompetition()?->getId() !== $competition->getId() || !$this->isCsrfTokenValid('competition-dispute-'.$dispute->getId(), (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException(); }
        $status = (string) $request->request->get('status');
        $decision = trim((string) $request->request->get('decision'));
        if (!in_array($status, [CompetitionDispute::STATUS_UPHELD, CompetitionDispute::STATUS_REJECTED], true) || $decision === '') { throw $this->createNotFoundException(); }
        $scoreA = $status === CompetitionDispute::STATUS_REJECTED && $match->getScoreA() !== null
            ? $match->getScoreA()
            : $this->nonNegativeScore($request, 'score_a');
        $scoreB = $status === CompetitionDispute::STATUS_REJECTED && $match->getScoreB() !== null
            ? $match->getScoreB()
            : $this->nonNegativeScore($request, 'score_b');
        $winnerId = (int) $request->request->get('winner');
        $winner = null;
        foreach ([$match->getParticipantA(), $match->getParticipantB()] as $candidate) {
            if ($candidate?->getId() === $winnerId) { $winner = $candidate; }
        }
        $expectedWinner = $scoreA === $scoreB ? null : ($scoreA > $scoreB ? $match->getParticipantA() : $match->getParticipantB());
        if ($status === CompetitionDispute::STATUS_REJECTED) {
            $winner = $expectedWinner;
        } elseif ($winner !== $expectedWinner) {
            throw $this->createNotFoundException();
        }
        $dispute->decide($this->currentUser(), $status, $decision);
        $match->resolveDispute($winner, $scoreA, $scoreB);
        $this->entityManager->flush();
        return $this->redirectToRoute('app_admin_competition_index');
    }

    private function assertAvailable(): void
    {
        if (!$this->modules->isEnabled('gaming')) { throw $this->createNotFoundException(); }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) { throw $this->createAccessDeniedException(); }
        return $user;
    }

    private function slug(string $name, ?int $exceptId = null): string
    {
        $base = mb_strtolower($this->slugger->slug($name)->toString()) ?: 'competition';
        $slug = $base;
        $suffix = 2;
        while ($this->competitions->slugExists($slug, $exceptId)) { $slug = $base.'-'.$suffix++; }
        return $slug;
    }

    private function nonNegativeScore(Request $request, string $key): int
    {
        $raw = $request->request->get($key);
        if (!is_string($raw) || !preg_match('/^\d+$/', $raw)) { throw $this->createNotFoundException(); }
        return (int) $raw;
    }
}

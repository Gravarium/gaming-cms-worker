<?php

declare(strict_types=1);

namespace App\Controller;

use App\Community\Interaction\ContentEntryTargetProvider;
use App\Community\Interaction\InteractionActor;
use App\Community\Interaction\InteractionAuthorization;
use App\Community\Interaction\InteractionTargetRegistry;
use App\Community\Interaction\ReportRecord;
use App\CommunityInteraction\ContentInteractionQuery;
use App\Entity\Community\CommunityComment;
use App\Entity\Community\CommunityModerationDecision;
use App\Entity\Community\CommunityReport;
use App\Entity\ContentEntry;
use App\Entity\User;
use App\Form\ContentModerationDecisionType;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/community/moderation')]
#[IsGranted(CmsPermission::CONTENT)]
final class AdminContentModerationController extends AbstractController
{
    public function __construct(
        private readonly ContentInteractionQuery $interactions,
        private readonly InteractionTargetRegistry $targets,
        private readonly InteractionAuthorization $authorization,
        private readonly CmsModuleManager $modules,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_admin_content_moderation', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->assertModuleEnabled();

        $reportCount = $this->interactions->countReportsForModeration();
        $reportPages = $this->pageCount($reportCount);
        $reportPage = min($this->requestedPage($request, 'reports_page'), $reportPages);
        $reports = $this->interactions->reportsForModeration($reportPage);
        $targets = [];
        foreach ($reports as $report) {
            $targetId = $report->getComment()->getTargetId();
            $targets[$targetId] = $this->interactions->entry($targetId);
        }

        $hiddenCount = $this->interactions->countHiddenContentComments();
        $hiddenPages = $this->pageCount($hiddenCount);
        $hiddenPage = min($this->requestedPage($request, 'hidden_page'), $hiddenPages);
        $hiddenComments = $this->interactions->hiddenContentComments($hiddenPage);
        $restoreForms = [];
        foreach ($hiddenComments as $comment) {
            $id = $comment->getId();
            if ($id !== null) {
                $restoreForms[$id] = $this->createForm(ContentModerationDecisionType::class, null, [
                    'actions' => ['restore'],
                    'csrf_token_id' => 'content-comment-restore-'.$id,
                ])->createView();
                $targets[$comment->getTargetId()] ??= $this->interactions->entry($comment->getTargetId());
            }
        }

        $decisionForms = [];
        foreach ($reports as $report) {
            $id = $report->getId();
            if ($id !== null) {
                $decisionForms[$id] = $this->createForm(ContentModerationDecisionType::class, null, [
                    'actions' => ['uphold', 'reject'],
                    'csrf_token_id' => 'content-report-decision-'.$id,
                ])->createView();
            }
        }

        $response = $this->render('admin/moderation.html.twig', [
            'reports' => $reports,
            'reportCount' => $reportCount,
            'reportPage' => $reportPage,
            'reportPages' => $reportPages,
            'hiddenComments' => $hiddenComments,
            'hiddenCount' => $hiddenCount,
            'hiddenPage' => $hiddenPage,
            'hiddenPages' => $hiddenPages,
            'decisionForms' => $decisionForms,
            'restoreForms' => $restoreForms,
            'targets' => $targets,
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function pageCount(int $total): int
    {
        return max(1, (int) ceil($total / ContentInteractionQuery::MODERATION_PAGE_SIZE));
    }

    private function requestedPage(Request $request, string $parameter): int
    {
        $value = $request->query->all()[$parameter] ?? null;
        if ($value === null && $request->isMethod('POST')) {
            $value = $request->request->all()[$parameter] ?? null;
        }
        if (!is_string($value) || preg_match('/\\A[0-9]{1,6}\\z/D', $value) !== 1) {
            return 1;
        }

        return max(1, (int) $value);
    }

    /** @return array{reports_page: int, hidden_page: int} */
    private function queuePageParameters(Request $request): array
    {
        return [
            'reports_page' => $this->requestedPage($request, 'reports_page'),
            'hidden_page' => $this->requestedPage($request, 'hidden_page'),
        ];
    }

    #[Route('/reports/{reportId}/review', name: 'app_admin_content_moderation_review', requirements: ['reportId' => '\d+'], methods: ['POST'])]
    public function review(int $reportId, Request $request): Response
    {
        $report = $this->report($reportId);
        $this->assertModeratable($report->getComment());
        if (!$this->validCsrf($request, 'content-report-review-'.$reportId)) {
            throw $this->createAccessDeniedException('Die Sicherheitsprüfung ist fehlgeschlagen.');
        }

        if ($report->getStatus() === ReportRecord::STATUS_OPEN) {
            $report->startReview();
            $this->entityManager->flush();
            $this->addFlash('success', 'Der Bericht wurde zur Prüfung übernommen.');
        } elseif ($report->getStatus() !== ReportRecord::STATUS_REVIEWING) {
            $this->addFlash('error', 'Dieser Bericht wurde bereits entschieden.');
        }

        return $this->redirectToRoute('app_admin_content_moderation', $this->queuePageParameters($request), Response::HTTP_SEE_OTHER);
    }

    #[Route('/reports/{reportId}/decision', name: 'app_admin_content_moderation_decide', requirements: ['reportId' => '\d+'], methods: ['POST'])]
    public function decide(int $reportId, Request $request): Response
    {
        $report = $this->report($reportId);
        $comment = $report->getComment();
        $moderator = $this->requireUser();
        $this->assertModeratable($comment);

        $form = $this->createForm(ContentModerationDecisionType::class, null, [
            'actions' => ['uphold', 'reject'],
            'csrf_token_id' => 'content-report-decision-'.$reportId,
        ])->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Wähle eine Entscheidung und gib eine Begründung bis 500 Zeichen an.');
            return $this->redirectToRoute('app_admin_content_moderation', $this->queuePageParameters($request), Response::HTTP_SEE_OTHER);
        }

        /** @var array{action?: string, reason?: string} $data */
        $data = $form->getData();
        $action = $data['action'] ?? '';
        $reason = trim((string) ($data['reason'] ?? ''));
        if (!in_array($action, ['uphold', 'reject'], true) || $reason === '') {
            $this->addFlash('error', 'Die Entscheidung oder Begründung ist ungültig.');
            return $this->redirectToRoute('app_admin_content_moderation', $this->queuePageParameters($request), Response::HTTP_SEE_OTHER);
        }
        if ($report->getStatus() === ReportRecord::STATUS_OPEN) {
            $report->startReview();
        }
        if ($report->getStatus() !== ReportRecord::STATUS_REVIEWING) {
            $this->addFlash('error', 'Dieser Bericht wurde bereits entschieden.');
            return $this->redirectToRoute('app_admin_content_moderation', $this->queuePageParameters($request), Response::HTTP_SEE_OTHER);
        }

        if ($action === 'uphold') {
            $report->decide(true, $moderator, $reason);
            if (!$comment->isDeleted()) {
                $comment->softDelete($moderator, $reason);
                $this->entityManager->persist(new CommunityModerationDecision(
                    $comment->getTargetType(),
                    $comment->getTargetId(),
                    $moderator,
                    'hide',
                    $reason,
                ));
            }
            $this->addFlash('success', 'Der Bericht wurde bestätigt.');
        } else {
            $report->decide(false, $moderator, $reason);
            $this->entityManager->persist(new CommunityModerationDecision(
                $comment->getTargetType(),
                $comment->getTargetId(),
                $moderator,
                'dismiss_report',
                $reason,
            ));
            $this->addFlash('success', 'Der Bericht wurde abgelehnt.');
        }

        $this->entityManager->flush();

        return $this->redirectToRoute('app_admin_content_moderation', $this->queuePageParameters($request), Response::HTTP_SEE_OTHER);
    }

    #[Route('/comments/{commentId}/restore', name: 'app_admin_content_moderation_restore', requirements: ['commentId' => '\d+'], methods: ['POST'])]
    public function restore(int $commentId, Request $request): Response
    {
        $comment = $this->entityManager->find(CommunityComment::class, $commentId);
        if (!$comment instanceof CommunityComment || $comment->getTargetType() !== ContentEntryTargetProvider::TYPE) {
            throw $this->createNotFoundException();
        }
        $moderator = $this->requireUser();
        $this->assertModeratable($comment);
        $form = $this->createForm(ContentModerationDecisionType::class, null, [
            'actions' => ['restore'],
            'csrf_token_id' => 'content-comment-restore-'.$commentId,
        ])->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid() || !$comment->isDeleted()) {
            $this->addFlash('error', 'Der Kommentar kann so nicht wiederhergestellt werden.');
            return $this->redirectToRoute('app_admin_content_moderation', $this->queuePageParameters($request), Response::HTTP_SEE_OTHER);
        }

        /** @var array{reason?: string} $data */
        $data = $form->getData();
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            $this->addFlash('error', 'Gib eine Begründung für die Wiederherstellung an.');
            return $this->redirectToRoute('app_admin_content_moderation', $this->queuePageParameters($request), Response::HTTP_SEE_OTHER);
        }

        $comment->restore();
        $this->entityManager->persist(new CommunityModerationDecision(
            $comment->getTargetType(),
            $comment->getTargetId(),
            $moderator,
            'restore',
            $reason,
        ));
        $this->entityManager->flush();
        $this->addFlash('success', 'Der Kommentar wurde wiederhergestellt.');

        return $this->redirectToRoute('app_admin_content_moderation', $this->queuePageParameters($request), Response::HTTP_SEE_OTHER);
    }

    private function report(int $reportId): CommunityReport
    {
        $this->assertModuleEnabled();
        $report = $this->entityManager->find(CommunityReport::class, $reportId);
        if (!$report instanceof CommunityReport || $report->getComment()->getTargetType() !== ContentEntryTargetProvider::TYPE) {
            throw $this->createNotFoundException();
        }

        return $report;
    }

    private function assertModeratable(CommunityComment $comment): void
    {
        $this->assertModuleEnabled();
        $target = $this->targets->resolve($comment->getTargetType(), $comment->getTargetId());
        if (!$this->authorization->canModerate($target, $this->actor())) {
            throw $this->createNotFoundException();
        }
    }

    private function assertModuleEnabled(): void
    {
        if (!$this->modules->isEnabled('content')) {
            throw $this->createNotFoundException();
        }
    }

    private function actor(): InteractionActor
    {
        $user = $this->getUser();

        return new InteractionActor($user instanceof User ? $user->getId() : null, true);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function validCsrf(Request $request, string $id): bool
    {
        $token = $request->request->get('_token');

        return is_string($token) && $this->isCsrfTokenValid($id, $token);
    }
}

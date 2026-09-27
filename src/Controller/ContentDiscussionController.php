<?php

declare(strict_types=1);

namespace App\Controller;

use App\Community\Interaction\ContentEntryTargetProvider;
use App\Community\Interaction\InteractionActor;
use App\Community\Interaction\InteractionAuthorization;
use App\Community\Interaction\InteractionTargetRegistry;
use App\Community\Interaction\ReactionPolicy;
use App\CommunityInteraction\ContentInteractionQuery;
use App\Entity\AdminNotification;
use App\Entity\Community\CommunityComment;
use App\Entity\Community\CommunityReaction;
use App\Entity\Community\CommunityReport;
use App\Entity\ContentEntry;
use App\Entity\User;
use App\Form\PublicContentCommentType;
use App\Form\PublicContentReportType;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/content/{slug}/discussion')]
final class ContentDiscussionController extends AbstractController
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private readonly ContentInteractionQuery $interactions,
        private readonly InteractionTargetRegistry $targets,
        private readonly InteractionAuthorization $authorization,
        private readonly ReactionPolicy $reactionPolicy,
        private readonly CmsModuleManager $modules,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'app_content_discussion', methods: ['GET'])]
    public function discussion(string $slug, Request $request): Response
    {
        return $this->renderDiscussion($this->publishedEntry($slug), $request);
    }

    #[Route('/comment', name: 'app_content_discussion_comment', methods: ['POST'])]
    public function addComment(string $slug, Request $request): Response
    {
        $entry = $this->publishedEntry($slug);
        $user = $this->requireInteractionUser($entry);
        $form = $this->createForm(PublicContentCommentType::class, [
            'body' => '',
            'parentId' => '',
        ], [
            'csrf_token_id' => 'content-comment-'.$entry->getId(),
        ])->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDiscussion($entry, $request, $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array{body?: string, parentId?: string} $data */
        $data = $form->getData();
        $parent = null;
        $parentId = $data['parentId'] ?? '';
        if ($parentId !== '') {
            if (!ctype_digit($parentId) || (int) $parentId < 1) {
                $form->get('parentId')->addError(new FormError('Die Antwort gehört nicht zu dieser Diskussion.'));
                return $this->renderDiscussion($entry, $request, $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $parent = $this->interactions->publicComment((int) $parentId, (int) $entry->getId());
            if (!$parent instanceof CommunityComment) {
                $form->get('parentId')->addError(new FormError('Der Kommentar ist nicht mehr verfügbar.'));
                return $this->renderDiscussion($entry, $request, $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        try {
            $comment = new CommunityComment(
                ContentEntryTargetProvider::TYPE,
                (int) $entry->getId(),
                $user,
                (string) ($data['body'] ?? ''),
                $parent,
            );
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $form->get('body')->addError(new FormError($exception->getMessage()));
            return $this->renderDiscussion($entry, $request, $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->persist($comment);
        $this->entityManager->flush();
        $this->addFlash('success', 'Dein Kommentar wurde veröffentlicht.');

        return $this->redirectToRoute('app_content_discussion', ['slug' => $entry->getSlug()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/comment/{commentId}/reaction', name: 'app_content_discussion_react', requirements: ['commentId' => '\d+'], methods: ['POST'])]
    public function react(string $slug, int $commentId, Request $request): Response
    {
        $entry = $this->publishedEntry($slug);
        $user = $this->requireInteractionUser($entry);
        $comment = $this->interactions->publicComment($commentId, (int) $entry->getId());
        if (!$comment instanceof CommunityComment) {
            throw $this->createNotFoundException();
        }

        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('content-reaction-'.$commentId, $token)) {
            throw $this->createAccessDeniedException('Die Sicherheitsprüfung ist fehlgeschlagen.');
        }

        $reaction = $request->request->get('reaction');
        if (!is_string($reaction) || !in_array($reaction, ReactionPolicy::ALLOWED, true)) {
            $this->addFlash('error', 'Bitte wähle eine gültige Reaktion.');
            return $this->redirectToRoute('app_content_discussion', ['slug' => $entry->getSlug()], Response::HTTP_SEE_OTHER);
        }

        $existing = $this->interactions->reaction($comment, $user, $reaction);
        if ($existing instanceof CommunityReaction) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
            $this->addFlash('success', 'Deine Reaktion wurde entfernt.');

            return $this->redirectToRoute('app_content_discussion', ['slug' => $entry->getSlug()], Response::HTTP_SEE_OTHER);
        }

        try {
            $this->reactionPolicy->assertCanAdd($this->interactions->targetReactionTypes((int) $entry->getId(), $user), $reaction);
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
            return $this->redirectToRoute('app_content_discussion', ['slug' => $entry->getSlug()], Response::HTTP_SEE_OTHER);
        }

        $this->entityManager->persist(new CommunityReaction($comment, $user, $reaction));
        $this->entityManager->flush();
        $this->addFlash('success', 'Deine Reaktion wurde gespeichert.');

        return $this->redirectToRoute('app_content_discussion', ['slug' => $entry->getSlug()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/comment/{commentId}/report', name: 'app_content_discussion_report', requirements: ['commentId' => '\d+'], methods: ['POST'])]
    public function report(string $slug, int $commentId, Request $request): Response
    {
        $entry = $this->publishedEntry($slug);
        $user = $this->requireInteractionUser($entry);
        $comment = $this->interactions->publicComment($commentId, (int) $entry->getId());
        if (!$comment instanceof CommunityComment) {
            throw $this->createNotFoundException();
        }
        if ($comment->getAuthor()->getId() === $user->getId()) {
            throw $this->createAccessDeniedException('Eigene Kommentare können nicht gemeldet werden.');
        }

        $form = $this->createForm(PublicContentReportType::class, null, [
            'csrf_token_id' => 'content-report-'.$commentId,
        ])->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->renderDiscussion(
                $entry,
                $request,
                reportForm: $form,
                reportFormCommentId: $commentId,
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ($this->interactions->activeReport($comment, $user) instanceof CommunityReport) {
            $this->addFlash('error', 'Du hast diesen Kommentar bereits gemeldet.');
            return $this->redirectToRoute('app_content_discussion', ['slug' => $entry->getSlug()], Response::HTTP_SEE_OTHER);
        }

        /** @var array{reason?: string, details?: string} $data */
        $data = $form->getData();
        try {
            $report = new CommunityReport($comment, $user, (string) ($data['reason'] ?? ''), $data['details'] ?? null);
        } catch (\InvalidArgumentException $exception) {
            $form->addError(new FormError($exception->getMessage()));
            return $this->renderDiscussion(
                $entry,
                $request,
                reportForm: $form,
                reportFormCommentId: $commentId,
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $this->entityManager->persist($report);
        $this->entityManager->persist(
            (new AdminNotification())
                ->setType('community_report')
                ->setTitle('Neuer Community-Bericht')
                ->setMessage('Ein Kommentar zu «'.$entry->getTitle().'» wurde gemeldet.')
                ->setLink('/admin/community/moderation'),
        );
        $this->entityManager->flush();
        $this->addFlash('success', 'Danke. Deine Meldung wurde an die Moderation weitergeleitet.');

        return $this->redirectToRoute('app_content_discussion', ['slug' => $entry->getSlug()], Response::HTTP_SEE_OTHER);
    }

    private function publishedEntry(string $slug): ContentEntry
    {
        if (!$this->modules->isEnabled('content')) {
            throw $this->createNotFoundException();
        }

        $entry = $this->interactions->publishedEntry($slug);
        if (!$entry instanceof ContentEntry) {
            throw $this->createNotFoundException();
        }

        $target = $this->targets->resolve(ContentEntryTargetProvider::TYPE, (int) $entry->getId());
        if (!$this->authorization->canView($target, $this->actor())) {
            throw $this->createNotFoundException();
        }

        return $entry;
    }

    private function requireInteractionUser(ContentEntry $entry): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Bitte melde dich an, um an der Diskussion teilzunehmen.');
        }

        $target = $this->targets->resolve(ContentEntryTargetProvider::TYPE, (int) $entry->getId());
        if (!$this->authorization->canInteract($target, $this->actor())) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function actor(): InteractionActor
    {
        $user = $this->getUser();

        return new InteractionActor(
            $user instanceof User ? $user->getId() : null,
            $this->isGranted(CmsPermission::CONTENT),
        );
    }

    /**
     * @param FormInterface<array{body?: string, parentId?: string}>|null $commentForm
     * @param FormInterface<array{reason?: string, details?: string}>|null $reportForm
     */
    private function renderDiscussion(
        ContentEntry $entry,
        Request $request,
        ?FormInterface $commentForm = null,
        ?FormInterface $reportForm = null,
        ?int $reportFormCommentId = null,
        int $status = Response::HTTP_OK,
    ): Response {
        $pageValue = $request->query->get('page', '1');
        if (!ctype_digit($pageValue) || (int) $pageValue < 1) {
            throw $this->createNotFoundException();
        }

        $total = $this->interactions->countPublicComments((int) $entry->getId());
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = (int) $pageValue;
        if ($page > $pages) {
            throw $this->createNotFoundException();
        }

        $replyTo = null;
        $replyId = $request->query->get('reply_to');
        if ($replyId !== null) {
            if (!ctype_digit((string) $replyId) || (int) $replyId < 1) {
                throw $this->createNotFoundException();
            }
            $replyTo = $this->interactions->publicComment((int) $replyId, (int) $entry->getId());
            if (!$replyTo instanceof CommunityComment) {
                throw $this->createNotFoundException();
            }
        }

        $comments = $this->interactions->publicComments((int) $entry->getId(), $page);
        $commentIds = array_values(array_filter(array_map(static fn (CommunityComment $comment): ?int => $comment->getId(), $comments)));
        $reactionCounts = $this->interactions->reactionCounts($commentIds);
        $user = $this->getUser();
        $userReactions = $user instanceof User ? $this->interactions->userReactions($commentIds, $user) : [];
        $commentRows = [];
        $reportForms = [];
        foreach ($comments as $comment) {
            $id = $comment->getId();
            if ($id === null) {
                continue;
            }
            $commentRows[] = [
                'comment' => $comment,
                'reactions' => $reactionCounts[$id] ?? [],
                'userReactions' => $userReactions[$id] ?? [],
            ];
            $reportForms[$id] = $reportFormCommentId === $id && $reportForm !== null
                ? $reportForm->createView()
                : $this->createForm(PublicContentReportType::class, null, [
                    'csrf_token_id' => 'content-report-'.$id,
                ])->createView();
        }

        $commentForm ??= $this->createForm(PublicContentCommentType::class, [
            'body' => '',
            'parentId' => $replyTo?->getId() === null ? '' : (string) $replyTo->getId(),
        ], [
            'csrf_token_id' => 'content-comment-'.$entry->getId(),
        ]);

        $contentRoute = $entry->getType() === ContentEntry::TYPE_NEWS ? 'app_news_show' : 'app_page_show';
        $response = $this->render('discussion.html.twig', [
            'entry' => $entry,
            'contentRoute' => $contentRoute,
            'comments' => $commentRows,
            'commentCount' => $total,
            'page' => $page,
            'pages' => $pages,
            'replyTo' => $replyTo,
            'commentForm' => $commentForm->createView(),
            'reportForms' => $reportForms,
        ], new Response('', $status));
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

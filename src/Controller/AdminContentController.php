<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentEditor\ContentBlockPolicy;
use App\ContentEditor\ContentBlockRenderer;
use App\Entity\ContentEntry;
use App\Entity\ContentRedirect;
use App\Entity\PageLayout;
use App\Entity\User;
use App\Form\ContentEntryType;
use App\Module\CmsModuleManager;
use App\Repository\CategoryRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\ContentRedirectRepository;
use App\Repository\ContentRevisionRepository;
use App\Repository\ContentTagRepository;
use App\Service\AuditLogger;
use App\Service\ContentRevisionManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/content')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminContentController extends AbstractController
{
    public function __construct(
        private readonly ContentEntryRepository $entries,
        private readonly ContentRevisionRepository $revisions,
        private readonly ContentRevisionManager $revisionManager,
        private readonly ContentRedirectRepository $redirects,
        private readonly CategoryRepository $categories,
        private readonly ContentTagRepository $tags,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface $slugger,
        private readonly AuditLogger $audit,
        private readonly ContentBlockPolicy $contentBlocks,
        private readonly ContentBlockRenderer $blockRenderer,
        private readonly CmsModuleManager $modules,
    ) {}

    #[Route('', name: 'app_admin_content_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = mb_substr(trim((string) $request->query->get('q')), 0, 160);
        $status = $request->query->getString('status');
        $type = $request->query->getString('type');
        $categorySlug = $request->query->getString('category');
        $tagSlug = $request->query->getString('tag');
        $category = $categorySlug !== '' ? $this->categories->findOneBy(['slug' => $categorySlug]) : null;
        $tag = $tagSlug !== '' ? $this->tags->findOneBySlug($tagSlug) : null;
        return $this->render('admin/content/index.html.twig', [
            'entries' => $this->entries->searchAdmin($query, $status, $type, $category, $tag),
            'filters' => ['q' => $query, 'status' => $status, 'type' => $type, 'category' => $categorySlug, 'tag' => $tagSlug],
            'categories' => $this->categories->findBy([], ['name' => 'ASC']),
            'tags' => $this->tags->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'app_admin_content_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $entry = new ContentEntry();
        $form = $this->createForm(ContentEntryType::class, $entry)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->requireUser();
            $entry->setAuthor($user)->setSlug($this->createUniqueSlug($entry->getSlug() ?: $entry->getTitle()));
            if ($this->synchronizeOrReject($entry, $form)) {
                $this->entityManager->persist($entry); $this->entityManager->flush();
                $this->revisionManager->capture($entry, $user);
                $this->audit->record('content.create', $entry, $entry->getId(), 'Inhalt erstellt.', ['status' => $entry->getStatus(), 'type' => $entry->getType()]);
                $this->entityManager->flush();
                $this->addFlash('success', 'Der Inhalt und seine erste Revision wurden gespeichert.');
                return $this->redirectToRoute('app_admin_content_edit', ['id' => $entry->getId()]);
            }
        }
        return $this->render('admin/content/form.html.twig', ['form' => $form, 'heading' => 'Neuen Inhalt erstellen']);
    }

    #[Route('/{id}/edit', name: 'app_admin_content_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(ContentEntry $entry, Request $request): Response
    {
        $oldSlug = $entry->getSlug();
        $oldType = $entry->getType();
        $form = $this->createForm(ContentEntryType::class, $entry)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entry->setSlug($this->createUniqueSlug($entry->getSlug() ?: $entry->getTitle(), $entry->getId()));
            if ($this->synchronizeOrReject($entry, $form)) {
                $user = $this->requireUser();
                $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($entry, $oldSlug, $oldType, $user): void {
                    if ($oldType !== $entry->getType()) {
                        $entityManager->lock($entry, LockMode::PESSIMISTIC_WRITE);
                        $this->removePageLayout($entry);
                    }
                    if ($oldSlug !== '' && $oldSlug !== $entry->getSlug()) { $this->rememberRedirect($entry->getType(), $oldSlug, $entry); }
                    $this->revisionManager->capture($entry, $user);
                    $this->audit->record('content.update', $entry, $entry->getId(), 'Inhalt bearbeitet.', ['status' => $entry->getStatus()]);
                });
                $this->addFlash('success', 'Die Änderungen wurden als neue Revision gespeichert.');
                return $this->redirectToRoute('app_admin_content_edit', ['id' => $entry->getId()]);
            }
        }
        return $this->render('admin/content/form.html.twig', ['form' => $form, 'heading' => 'Inhalt bearbeiten', 'entry' => $entry]);
    }

    #[Route('/{id}/preview', name: 'app_admin_content_preview', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function preview(ContentEntry $entry): Response
    {
        $response = $this->render('content/show.html.twig', ['entry' => $entry, 'related' => [], 'preview' => true]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        return $response;
    }

    #[Route('/{id}/editor/preview', name: 'app_admin_content_editor_preview', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function editorPreview(ContentEntry $entry, Request $request): JsonResponse
    {
        $this->assertContentModuleEnabled();
        $this->assertEditorCsrf($entry, $request);
        try {
            $payload = $this->editorPayload($request);
            $document = is_string($payload['document'] ?? null) ? $payload['document'] : '';
            $normalized = $this->contentBlocks->normalizeForStorage($document);
        } catch (\InvalidArgumentException|\JsonException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }

        return $this->json(['html' => $this->blockRenderer->render($normalized), 'document' => $normalized]);
    }

    #[Route('/{id}/editor/autosave', name: 'app_admin_content_editor_autosave', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function editorAutosave(ContentEntry $entry, Request $request): JsonResponse
    {
        $this->assertContentModuleEnabled();
        $this->assertEditorCsrf($entry, $request);
        if (!in_array($entry->getStatus(), [ContentEntry::STATUS_DRAFT, ContentEntry::STATUS_REVIEW], true)) {
            return $this->json(['error' => 'Autosave ist nur für Entwurf oder Freigabe-Status erlaubt.'], 409);
        }

        try {
            $payload = $this->editorPayload($request);
            $document = is_string($payload['document'] ?? null) ? $payload['document'] : '';
            $expectedUpdatedAt = is_string($payload['updatedAt'] ?? null) ? $payload['updatedAt'] : '';
            $normalized = $this->contentBlocks->normalizeForStorage($document);
        } catch (\InvalidArgumentException|\JsonException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }

        if ($expectedUpdatedAt === '' || !hash_equals($entry->getUpdatedAt()->format(DATE_ATOM), $expectedUpdatedAt)) {
            return $this->json(['error' => 'Der Inhalt wurde zwischenzeitlich geändert. Bitte neu laden.'], 409);
        }
        if ($normalized === $entry->getBody()) {
            return $this->json(['document' => $normalized, 'updatedAt' => $expectedUpdatedAt, 'unchanged' => true]);
        }

        $user = $this->requireUser();
        try {
            $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($entry, $normalized, $expectedUpdatedAt, $user): void {
                $entityManager->refresh($entry, LockMode::PESSIMISTIC_WRITE);
                if (!hash_equals($entry->getUpdatedAt()->format(DATE_ATOM), $expectedUpdatedAt)) {
                    throw new ConflictHttpException('Der Inhalt wurde zwischenzeitlich geändert.');
                }
                if (!in_array($entry->getStatus(), [ContentEntry::STATUS_DRAFT, ContentEntry::STATUS_REVIEW], true)) {
                    throw new ConflictHttpException('Autosave ist für diesen Status nicht erlaubt.');
                }
                $entry->setBody($normalized);
                $this->revisionManager->capture($entry, $user);
                $this->audit->record('content.autosave', $entry, $entry->getId(), 'Editor-Entwurf automatisch gesichert.');
            });
        } catch (ConflictHttpException $exception) {
            return $this->json(['error' => $exception->getMessage()], 409);
        }

        return $this->json(['document' => $entry->getBody(), 'updatedAt' => $entry->getUpdatedAt()->format(DATE_ATOM), 'unchanged' => false]);
    }

    #[Route('/{id}/duplicate', name: 'app_admin_content_duplicate', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function duplicate(ContentEntry $entry, Request $request): Response
    {
        $this->assertCsrf('duplicate-content-'.$entry->getId(), $request);
        $copy = (new ContentEntry())->setAuthor($this->requireUser())->setType($entry->getType())->setTitle('Kopie von '.$entry->getTitle())
            ->setSubtitle($entry->getSubtitle())->setSlug($this->createUniqueSlug($entry->getTitle().'-kopie'))->setExcerpt($entry->getExcerpt())
            ->setBody($entry->getBody())->setCategory($entry->getCategory())->setStatus(ContentEntry::STATUS_DRAFT)->setFeatured(false)->setPinned(false)
            ->setUnlisted($entry->isUnlisted())->setSeoTitle($entry->getSeoTitle())->setSeoDescription($entry->getSeoDescription())->setCanonicalUrl(null)->setNoIndex(true);
        foreach ($entry->getTags() as $tag) { $copy->addTag($tag); }
        $this->entityManager->persist($copy); $this->entityManager->flush();
        $this->revisionManager->capture($copy, $this->requireUser());
        $this->audit->record('content.duplicate', $copy, $copy->getId(), 'Inhalt dupliziert.', ['sourceId' => $entry->getId()]);
        $this->entityManager->flush(); $this->addFlash('success', 'Eine Entwurfskopie wurde erstellt.');
        return $this->redirectToRoute('app_admin_content_edit', ['id' => $copy->getId()]);
    }

    #[Route('/{id}/history', name: 'app_admin_content_history', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function history(ContentEntry $entry): Response { return $this->render('admin/content/history.html.twig', ['entry' => $entry, 'revisions' => $this->revisions->forEntry($entry)]); }

    #[Route('/{id}/revisions/{revisionId}', name: 'app_admin_content_revision_show', requirements: ['id' => '\\d+', 'revisionId' => '\\d+'], methods: ['GET'])]
    public function revision(ContentEntry $entry, int $revisionId): Response
    {
        $revision = $this->revisions->find($revisionId);
        if ($revision === null || $revision->getEntry() !== $entry) { throw $this->createNotFoundException(); }
        return $this->render('admin/content/revision.html.twig', ['entry' => $entry, 'revision' => $revision]);
    }

    #[Route('/{id}/revisions/{revisionId}/restore', name: 'app_admin_content_restore', requirements: ['id' => '\\d+', 'revisionId' => '\\d+'], methods: ['POST'])]
    public function restore(ContentEntry $entry, int $revisionId, Request $request): Response
    {
        $revision = $this->revisions->find($revisionId);
        if ($revision === null || $revision->getEntry() !== $entry) { throw $this->createNotFoundException(); }
        $this->assertCsrf('restore-content-'.$entry->getId().'-'.$revisionId, $request);
        $user = $this->requireUser();
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($entry, $revision, $user): void {
            $entityManager->refresh($entry, LockMode::PESSIMISTIC_WRITE);
            $oldType = $entry->getType();
            $this->revisionManager->restore($entry, $revision, $user);
            if ($oldType !== $entry->getType()) { $this->removePageLayout($entry); }
            $this->audit->record('content.restore_revision', $entry, $entry->getId(), 'Content-Revision wiederhergestellt.', ['revision' => $revision->getRevisionNumber()]);
        });
        $this->addFlash('success', 'Revision '.$revision->getRevisionNumber().' wurde als Entwurf wiederhergestellt.');
        return $this->redirectToRoute('app_admin_content_edit', ['id' => $entry->getId()]);
    }

    #[Route('/{id}/trash', name: 'app_admin_content_trash', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function trash(ContentEntry $entry, Request $request): Response
    {
        $this->assertCsrf('trash-content-'.$entry->getId(), $request);
        if ($entry->getStatus() !== ContentEntry::STATUS_TRASHED) {
            $this->revisionManager->capture($entry, $this->requireUser()); $entry->trash();
            $this->audit->record('content.trash', $entry, $entry->getId(), 'Inhalt in den Papierkorb verschoben.'); $this->entityManager->flush();
        }
        $this->addFlash('success', 'Der Inhalt wurde in den Papierkorb verschoben.');
        return $this->redirectToRoute('app_admin_content_index', ['status' => ContentEntry::STATUS_TRASHED]);
    }

    #[Route('/{id}/restore-trash', name: 'app_admin_content_restore_trash', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function restoreTrash(ContentEntry $entry, Request $request): Response
    {
        $this->assertCsrf('restore-trash-content-'.$entry->getId(), $request); $entry->restoreFromTrash();
        $this->audit->record('content.restore_trash', $entry, $entry->getId(), 'Inhalt aus dem Papierkorb wiederhergestellt.');
        $this->entityManager->flush(); $this->addFlash('success', 'Der Inhalt wurde als Entwurf wiederhergestellt.');
        return $this->redirectToRoute('app_admin_content_edit', ['id' => $entry->getId()]);
    }

    #[Route('/{id}/purge', name: 'app_admin_content_purge', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function purge(ContentEntry $entry, Request $request): Response
    {
        $this->assertCsrf('purge-content-'.$entry->getId(), $request);
        $id = $entry->getId();
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($entry, $id): void {
            $entityManager->refresh($entry, LockMode::PESSIMISTIC_WRITE);
            if ($entry->getStatus() !== ContentEntry::STATUS_TRASHED) { throw $this->createAccessDeniedException('Nur Inhalte aus dem Papierkorb können endgültig gelöscht werden.'); }
            $this->removePageLayout($entry);
            $this->audit->record('content.purge', ContentEntry::class, $id, 'Inhalt endgültig gelöscht.');
            $entityManager->remove($entry);
        }); $this->addFlash('success', 'Der Inhalt wurde endgültig gelöscht.');
        return $this->redirectToRoute('app_admin_content_index', ['status' => ContentEntry::STATUS_TRASHED]);
    }

    #[Route('/bulk', name: 'app_admin_content_bulk', methods: ['POST'])]
    public function bulk(Request $request): Response
    {
        $this->assertCsrf('content-bulk', $request);
        $ids = array_values(array_unique(array_filter(array_map('intval', $request->request->all('ids')), static fn (int $id): bool => $id > 0)));
        if (count($ids) > 200) { throw $this->createAccessDeniedException('Zu viele Objekte für eine Bulk-Aktion.'); }
        $action = $request->request->getString('action');
        if (!in_array($action, ['draft', 'review', 'archive', 'trash', 'feature', 'unfeature', 'pin', 'unpin'], true)) { throw $this->createNotFoundException(); }
        $changed = 0;
        foreach ($ids as $id) {
            $entry = $this->entries->find($id); if (!$entry instanceof ContentEntry) { continue; }
            match ($action) {
                'draft' => $entry->setStatus(ContentEntry::STATUS_DRAFT)->synchronizePublication(),
                'review' => $entry->setStatus(ContentEntry::STATUS_REVIEW)->synchronizePublication(),
                'archive' => $entry->setStatus(ContentEntry::STATUS_ARCHIVED)->synchronizePublication(),
                'trash' => $entry->trash(),
                'feature' => $entry->setFeatured(true),
                'unfeature' => $entry->setFeatured(false),
                'pin' => $entry->setPinned(true),
                'unpin' => $entry->setPinned(false),
            };
            ++$changed;
        }
        $this->audit->record('content.bulk', ContentEntry::class, null, 'Bulk-Aktion auf Inhalte angewendet.', ['actionName' => $action, 'count' => $changed]);
        $this->entityManager->flush(); $this->addFlash('success', sprintf('%d Inhalte wurden aktualisiert.', $changed));
        return $this->redirectToRoute('app_admin_content_index');
    }

    private function removePageLayout(ContentEntry $entry): void
    {
        $id = $entry->getId();
        if ($id === null) { return; }
        $layout = $this->entityManager->find(PageLayout::class, 'page-'.$id);
        if ($layout !== null) { $this->entityManager->remove($layout); }
    }

    /** @param FormInterface<mixed> $form */
    private function synchronizeOrReject(ContentEntry $entry, FormInterface $form): bool
    {
        try {
            $entry->setBody($this->contentBlocks->normalizeForStorage($entry->getBody()));
            $entry->synchronizePublication();
            return true;
        } catch (\DomainException|\InvalidArgumentException $exception) {
            $form->addError(new FormError($exception->getMessage()));
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function editorPayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Ungültige Editor-Anfrage.');
        }
        return $payload;
    }

    private function assertContentModuleEnabled(): void
    {
        if (!$this->modules->isEnabled('content')) {
            throw $this->createNotFoundException();
        }
    }

    private function assertEditorCsrf(ContentEntry $entry, Request $request): void
    {
        $token = (string) $request->headers->get('X-CSRF-TOKEN');
        if (!$this->isCsrfTokenValid('content-editor-'.$entry->getId(), $token)) {
            throw $this->createAccessDeniedException('Ungültige Sicherheitsprüfung.');
        }
    }
    private function requireUser(): User { $user = $this->getUser(); if (!$user instanceof User) { throw $this->createAccessDeniedException(); } return $user; }
    private function createUniqueSlug(string $raw, ?int $exceptId = null): string
    {
        $base = mb_strtolower($this->slugger->slug($raw)->toString()); $base = $base !== '' ? mb_substr($base, 0, 180) : 'inhalt'; $slug = $base; $suffix = 2;
        while ($this->entries->slugExists($slug, $exceptId)) { $tail = '-'.$suffix++; $slug = mb_substr($base, 0, 200 - mb_strlen($tail)).$tail; }
        return $slug;
    }
    private function rememberRedirect(string $type, string $oldSlug, ContentEntry $target): void
    {
        if ($this->redirects->findTarget($type, $oldSlug) === null) { $this->entityManager->persist(new ContentRedirect($target, $type, $oldSlug)); }
    }
    private function assertCsrf(string $id, Request $request): void { if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) { throw $this->createAccessDeniedException('Ungültige Sicherheitsprüfung.'); } }
}

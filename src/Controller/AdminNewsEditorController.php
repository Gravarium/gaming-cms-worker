<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentEditor\ContentBlockPolicy;
use App\ContentEditor\ContentBlockRenderer;
use App\ContentEditor\OwnedMediaReferenceGateway;
use App\Entity\ContentEntry;
use App\Entity\User;
use App\Module\CmsModuleManager;
use App\NewsEditor\LegacyNewsConverter;
use App\NewsEditor\RichDocument;
use App\Repository\ContentEntryRepository;
use App\Repository\MediaAssetRepository;
use App\Service\AuditLogger;
use App\Service\ContentRevisionManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/news-editor')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminNewsEditorController extends AbstractController
{
    private const MAX_REQUEST_BYTES = 524288;

    public function __construct(
        private readonly CmsModuleManager $modules,
        private readonly EntityManagerInterface $entityManager,
        private readonly LegacyNewsConverter $converter,
        private readonly ContentBlockPolicy $policy,
        private readonly ContentBlockRenderer $renderer,
        private readonly ContentRevisionManager $revisions,
        private readonly AuditLogger $audit,
        private readonly MediaAssetRepository $assets,
        private readonly OwnedMediaReferenceGateway $media,
        private readonly ContentEntryRepository $entries,
        private readonly SluggerInterface $slugger,
    ) {}

    #[Route('', name: 'app_admin_news_editor_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->modules->isEnabled('content')) {
            throw $this->createNotFoundException();
        }
        $params = $request->query->all();
        $status = $params['status'] ?? 'all';
        $search = $params['q'] ?? '';
        $pageInput = $params['page'] ?? '1';
        if (!is_string($status) || !in_array($status, ['all', ContentEntry::STATUS_DRAFT, ContentEntry::STATUS_REVIEW], true)
            || !is_string($search) || !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 80
            || preg_match('/[\x00-\x1f\x7f]/u', $search) === 1
            || !is_string($pageInput) || preg_match('/\A[1-9][0-9]{0,3}\z/D', $pageInput) !== 1
            || (int) $pageInput > 1000) {
            throw $this->createNotFoundException();
        }
        $search = trim($search);
        $page = (int) $pageInput;
        $query = $this->entityManager->getRepository(ContentEntry::class)->createQueryBuilder('entry')
            ->andWhere('entry.type = :type')->setParameter('type', ContentEntry::TYPE_NEWS)
            ->andWhere('entry.status IN (:statuses)')
            ->setParameter('statuses', $status === 'all' ? [ContentEntry::STATUS_DRAFT, ContentEntry::STATUS_REVIEW] : [$status])
            ->orderBy('entry.updatedAt', 'DESC')->addOrderBy('entry.id', 'DESC')
            ->setFirstResult(($page - 1) * 25)->setMaxResults(26);
        if ($search !== '') {
            $query->andWhere('LOCATE(:search, LOWER(entry.title)) > 0')
                ->setParameter('search', mb_strtolower($search));
        }
        $entries = $query->getQuery()->getResult();
        $hasNext = count($entries) > 25;
        if ($hasNext) {
            array_pop($entries);
        }
        $response = $this->render('admin/news_editor/index.html.twig', [
            'entries' => $entries, 'search' => $search, 'status' => $status, 'page' => $page, 'hasNext' => $hasNext,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        return $response;
    }

    #[Route('/new', name: 'app_admin_news_editor_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if (!$this->modules->isEnabled('content')) {
            throw $this->createNotFoundException();
        }
        $title = '';
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('news-editor-new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Ungültige Sicherheitsprüfung.');
            }
            $submitted = $request->request->get('title');
            $title = is_string($submitted) ? trim($submitted) : '';
            if ($title === '' || !mb_check_encoding($title, 'UTF-8') || mb_strlen($title) > 180 || preg_match('/[\x00-\x1f\x7f]/u', $title) === 1) {
                $error = 'Bitte einen Titel mit höchstens 180 Zeichen eingeben.';
            } else {
                $user = $this->getUser();
                if (!$user instanceof User) {
                    throw $this->createAccessDeniedException();
                }
                $base = mb_strtolower($this->slugger->slug($title)->toString());
                $base = $base === '' ? 'news' : mb_substr($base, 0, 180);
                $slug = $base;
                for ($suffix = 2; $this->entries->slugExists($slug); $suffix++) {
                    $tail = '-'.$suffix;
                    $slug = mb_substr($base, 0, 200 - mb_strlen($tail)).$tail;
                }
                $document = RichDocument::PREFIX.json_encode(['version' => 2, 'blocks' => [
                    ['type' => 'paragraph', 'content' => [['text' => '', 'marks' => []]]],
                ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $entry = (new ContentEntry())->setType(ContentEntry::TYPE_NEWS)->setStatus(ContentEntry::STATUS_DRAFT)
                    ->setAuthor($user)->setTitle($title)->setSlug($slug)->setBody('')->setEditorDocument($document);
                $this->entityManager->wrapInTransaction(function (EntityManagerInterface $manager) use ($entry, $user): void {
                    $manager->persist($entry);
                    $manager->flush();
                    $this->revisions->capture($entry, $user);
                    $this->audit->record('content.create', $entry, $entry->getId(), 'News-Entwurf im visuellen Editor erstellt.', ['status' => ContentEntry::STATUS_DRAFT, 'type' => ContentEntry::TYPE_NEWS]);
                });
                return $this->redirectToRoute('app_admin_news_editor', ['id' => $entry->getId()]);
            }
        }
        $response = $this->render('admin/news_editor/new.html.twig', ['title' => $title, 'error' => $error], new Response(status: $error === null ? 200 : 422));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        return $response;
    }

    #[Route('/{id}', name: 'app_admin_news_editor', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(ContentEntry $entry, Request $request): Response
    {
        $this->assertEditable($entry);
        $document = $this->converter->forEditing($entry->getEditableDocument());
        $error = null;
        if ($request->isMethod('POST')) {
            $this->assertCsrf($entry, (string) $request->request->get('_token'));
            $submitted = $request->request->get('document');
            $expected = $request->request->get('updatedAt');
            $expectedHash = $request->request->get('documentHash');
            if (!is_string($submitted) || strlen($submitted) > self::MAX_REQUEST_BYTES || !is_string($expected) || !is_string($expectedHash) || preg_match('/\A[a-f0-9]{64}\z/D', $expectedHash) !== 1) {
                return $this->errorResponse($entry, $document, 'Ungültige oder zu große Editor-Anfrage.', 413);
            }
            $document = $submitted;
            try {
                $normalized = $this->policy->normalizeForStorage($submitted);
                $plainText = $this->policy->plainText($normalized);
                if ($plainText === '') {
                    throw new \InvalidArgumentException('Ein News-Artikel braucht lesbaren Inhalt.');
                }
                $user = $this->getUser();
                if (!$user instanceof User) {
                    throw $this->createAccessDeniedException();
                }
                $this->entityManager->wrapInTransaction(function (EntityManagerInterface $manager) use ($entry, $normalized, $plainText, $expected, $expectedHash, $user): void {
                    $manager->refresh($entry, LockMode::PESSIMISTIC_WRITE);
                    $this->assertEditable($entry);
                    if (!hash_equals($entry->getUpdatedAt()->format(DATE_ATOM), $expected)
                        || !hash_equals(hash('sha256', $entry->getEditableDocument()), $expectedHash)) {
                        throw new \DomainException('Der Artikel wurde inzwischen geändert. Bitte neu laden.');
                    }
                    $entry->setEditorDocument($normalized)->setBody($plainText);
                    $this->revisions->capture($entry, $user);
                    $this->audit->record('content.rich_editor.update', $entry, $entry->getId(), 'News-Entwurf im Rich-Text-Editor gespeichert.');
                });
                $this->addFlash('success', 'News-Entwurf als Revision gespeichert.');
                return $this->redirectToRoute('app_admin_news_editor', ['id' => $entry->getId()]);
            } catch (\InvalidArgumentException|\DomainException $exception) {
                $error = $exception->getMessage();
            }
        }
        return $this->page($entry, $document, $error, $error === null ? 200 : 422);
    }

    #[Route('/{id}/submit-review', name: 'app_admin_news_editor_submit_review', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function submitReview(ContentEntry $entry, Request $request): Response
    {
        $this->assertEditable($entry);
        $this->assertCsrf($entry, (string) $request->request->get('_token'));
        $submitted = $request->request->get('document');
        $expected = $request->request->get('updatedAt');
        $expectedHash = $request->request->get('documentHash');
        if (!is_string($submitted) || strlen($submitted) > self::MAX_REQUEST_BYTES
            || !is_string($expected) || !is_string($expectedHash)
            || preg_match('/\A[a-f0-9]{64}\z/D', $expectedHash) !== 1) {
            return $this->errorResponse($entry, is_string($submitted) ? $submitted : '', 'Ungültige oder zu große Editor-Anfrage.', 413);
        }

        try {
            $normalized = $this->policy->normalizeForStorage($submitted);
            $plainText = $this->policy->plainText($normalized);
            if ($plainText === '') {
                throw new \InvalidArgumentException('Ein News-Artikel braucht lesbaren Inhalt.');
            }
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw $this->createAccessDeniedException();
            }
            $this->entityManager->wrapInTransaction(function (EntityManagerInterface $manager) use ($entry, $normalized, $plainText, $expected, $expectedHash, $user): void {
                $manager->refresh($entry, LockMode::PESSIMISTIC_WRITE);
                $this->assertEditable($entry);
                if ($entry->getStatus() !== ContentEntry::STATUS_DRAFT) {
                    throw new \DomainException('Nur Entwürfe können zur Freigabe eingereicht werden.');
                }
                if (!hash_equals($entry->getUpdatedAt()->format(DATE_ATOM), $expected)
                    || !hash_equals(hash('sha256', $entry->getEditableDocument()), $expectedHash)) {
                    throw new \DomainException('Der Artikel wurde inzwischen geändert. Bitte neu laden.');
                }
                $entry->setEditorDocument($normalized)->setBody($plainText)
                    ->setStatus(ContentEntry::STATUS_REVIEW)->synchronizePublication();
                $this->revisions->capture($entry, $user);
                $this->audit->record('content.review.submit', $entry, $entry->getId(), 'News-Entwurf aus dem visuellen Editor zur Freigabe eingereicht.');
            });
            $this->addFlash('success', 'Der News-Entwurf wurde zur Freigabe eingereicht.');
            return $this->redirectToRoute('app_admin_news_editor', ['id' => $entry->getId()]);
        } catch (\DomainException $exception) {
            return $this->errorResponse($entry, $submitted, $exception->getMessage(), 409);
        } catch (\InvalidArgumentException $exception) {
            return $this->errorResponse($entry, $submitted, $exception->getMessage(), 422);
        }
    }

    #[Route('/{id}/preview', name: 'app_admin_news_editor_preview', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function preview(ContentEntry $entry, Request $request): JsonResponse
    {
        $this->assertEditable($entry);
        $this->assertCsrf($entry, (string) $request->headers->get('X-CSRF-TOKEN'));
        if (strlen($request->getContent()) > self::MAX_REQUEST_BYTES) {
            return $this->json(['error' => 'Editor-Anfrage ist zu groß.'], 413);
        }
        try {
            $payload = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !is_string($payload['document'] ?? null)) {
                throw new \InvalidArgumentException('Ungültige Vorschau-Anfrage.');
            }
            $document = $this->policy->normalizeForStorage($payload['document']);
            $response = $this->json(['html' => $this->renderer->render($document)]);
        } catch (\JsonException|\InvalidArgumentException $exception) {
            $response = $this->json(['error' => $exception->getMessage()], 422);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/{id}/autosave', name: 'app_admin_news_editor_autosave', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function autosave(ContentEntry $entry, Request $request): JsonResponse
    {
        $this->assertEditable($entry);
        $this->assertCsrf($entry, (string) $request->headers->get('X-CSRF-TOKEN'));
        if (strlen($request->getContent()) > self::MAX_REQUEST_BYTES) {
            return $this->autosaveError('Editor-Anfrage ist zu groß.', 413);
        }
        try {
            $payload = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \InvalidArgumentException('Ungültige Autosave-Anfrage.');
            }
            $submitted = $payload['document'] ?? null;
            $expected = $payload['updatedAt'] ?? null;
            $expectedHash = $payload['documentHash'] ?? null;
            if (!is_string($submitted) || !is_string($expected) || !is_string($expectedHash)
                || preg_match('/\A[a-f0-9]{64}\z/D', $expectedHash) !== 1) {
                throw new \InvalidArgumentException('Ungültige Autosave-Anfrage.');
            }
            $normalized = $this->policy->normalizeForStorage($submitted);
            $plainText = $this->policy->plainText($normalized);
            if ($plainText === '') {
                throw new \InvalidArgumentException('Ein News-Artikel braucht lesbaren Inhalt.');
            }
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw $this->createAccessDeniedException();
            }
            $this->entityManager->wrapInTransaction(function (EntityManagerInterface $manager) use ($entry, $normalized, $plainText, $expected, $expectedHash, $user): void {
                $manager->refresh($entry, LockMode::PESSIMISTIC_WRITE);
                $this->assertEditable($entry);
                if (!hash_equals($entry->getUpdatedAt()->format(DATE_ATOM), $expected)
                    || !hash_equals(hash('sha256', $entry->getEditableDocument()), $expectedHash)) {
                    throw new \DomainException('Der Artikel wurde inzwischen geändert. Bitte neu laden.');
                }
                if ($entry->getEditableDocument() !== $normalized) {
                    $entry->setEditorDocument($normalized)->setBody($plainText);
                    $manager->flush();
                    $this->revisions->capture($entry, $user);
                    $this->audit->record('content.rich_editor.autosave', $entry, $entry->getId(), 'News-Entwurf automatisch als Revision gespeichert.');
                }
            });
            $response = $this->json([
                'updatedAt' => $entry->getUpdatedAt()->format(DATE_ATOM),
                'documentHash' => hash('sha256', $entry->getEditableDocument()),
            ]);
        } catch (\DomainException $exception) {
            return $this->autosaveError($exception->getMessage(), 409);
        } catch (\JsonException|\InvalidArgumentException $exception) {
            return $this->autosaveError($exception->getMessage(), 422);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    private function autosaveError(string $error, int $status): JsonResponse
    {
        $response = $this->json(['error' => $error], $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/{id}/media', name: 'app_admin_news_editor_media', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function media(ContentEntry $entry, Request $request): JsonResponse
    {
        $this->assertEditable($entry);
        if ($request->query->has('id')) {
            $id = $request->query->get('id');
            if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) !== 1
                || strlen($id) > strlen((string) PHP_INT_MAX)
                || (strlen($id) === strlen((string) PHP_INT_MAX) && strcmp($id, (string) PHP_INT_MAX) > 0)) {
                return $this->json(['error' => 'Ungültige Medien-ID.'], 422);
            }
            $asset = $this->media->resolve((int) $id);
            $response = $this->json(['items' => $asset === null ? [] : [$asset]]);
            $response->headers->set('Cache-Control', 'private, no-store');
            return $response;
        }
        $query = $request->query->get('q', '');
        if (mb_strlen($query) > 80) {
            return $this->json(['error' => 'Ungültige Mediensuche.'], 422);
        }
        $items = [];
        foreach ($this->assets->searchLibrary($query, 'content', null, 'image') as $asset) {
            $id = $asset->getId();
            if ($id === null || ($reference = $this->media->resolve($id)) === null) {
                continue;
            }
            $items[] = $reference;
        }
        $response = $this->json(['items' => $items]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    private function assertEditable(ContentEntry $entry): void
    {
        if (!$this->modules->isEnabled('content') || $entry->getType() !== ContentEntry::TYPE_NEWS) {
            throw $this->createNotFoundException();
        }
        if (!in_array($entry->getStatus(), [ContentEntry::STATUS_DRAFT, ContentEntry::STATUS_REVIEW], true)) {
            throw $this->createAccessDeniedException('Nur Entwürfe und Beiträge in Prüfung können hier geändert werden.');
        }
    }

    private function assertCsrf(ContentEntry $entry, string $token): void
    {
        if (!$this->isCsrfTokenValid('news-editor-'.$entry->getId(), $token)) {
            throw $this->createAccessDeniedException('Ungültige Sicherheitsprüfung.');
        }
    }

    private function errorResponse(ContentEntry $entry, string $document, string $error, int $status): Response
    {
        return $this->page($entry, $document, $error, $status);
    }

    private function page(ContentEntry $entry, string $document, ?string $error, int $status): Response
    {
        $response = $this->render('admin/news_editor/edit.html.twig', [
            'entry' => $entry,
            'document' => $document,
            'documentHash' => hash('sha256', $entry->getEditableDocument()),
            'error' => $error,
        ], new Response(status: $status));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        return $response;
    }
}

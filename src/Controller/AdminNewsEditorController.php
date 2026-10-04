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
    ) {}

    #[Route('', name: 'app_admin_news_editor_index', methods: ['GET'])]
    public function index(): Response
    {
        if (!$this->modules->isEnabled('content')) {
            throw $this->createNotFoundException();
        }
        $entries = $this->entityManager->getRepository(ContentEntry::class)->findBy([
            'type' => ContentEntry::TYPE_NEWS,
            'status' => [ContentEntry::STATUS_DRAFT, ContentEntry::STATUS_REVIEW],
        ], ['updatedAt' => 'DESC'], 100);
        $response = $this->render('admin/news_editor/index.html.twig', ['entries' => $entries]);
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

    #[Route('/{id}/media', name: 'app_admin_news_editor_media', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function media(ContentEntry $entry, Request $request): JsonResponse
    {
        $this->assertEditable($entry);
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

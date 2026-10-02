<?php

declare(strict_types=1);

namespace App\Controller;

use App\ContentEditor\ContentBlockPolicy;
use App\Entity\NewsBlockSnippet;
use App\Entity\User;
use App\Module\CmsModuleManager;
use App\NewsEditor\RichDocument;
use App\Repository\NewsBlockSnippetRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/news-editor/snippets')]
#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminNewsBlockSnippetController extends AbstractController
{
    private const MAX_SNIPPETS = 50;
    private const MAX_REQUEST_BYTES = 20000;

    public function __construct(
        private readonly CmsModuleManager $modules,
        private readonly NewsBlockSnippetRepository $snippets,
        private readonly ContentBlockPolicy $policy,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: 'app_admin_news_editor_snippets', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $user = $this->user();
        $query = $request->query->all()['q'] ?? '';
        if (!is_string($query) || !mb_check_encoding($query, 'UTF-8') || mb_strlen($query) > 80 || preg_match('/[\x00-\x1f\x7f]/u', $query) === 1) {
            return $this->privateJson(['error' => 'Ungültige Vorlagensuche.'], 422);
        }
        $items = array_map(static fn (NewsBlockSnippet $snippet): array => [
            'id' => $snippet->getId(), 'label' => $snippet->getLabel(), 'block' => $snippet->getBlock(),
            'updatedAt' => $snippet->getUpdatedAt()->format(DATE_ATOM),
        ], $this->snippets->forOwner($user, trim($query)));
        return $this->privateJson(['items' => $items, 'capacity' => self::MAX_SNIPPETS]);
    }

    #[Route('', name: 'app_admin_news_editor_snippet_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->user();
        if (!$this->isCsrfTokenValid('news-editor-snippets', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            throw $this->createAccessDeniedException('Ungültige Sicherheitsprüfung.');
        }
        if (strlen($request->getContent()) > self::MAX_REQUEST_BYTES) {
            return $this->privateJson(['error' => 'Blockvorlage ist zu groß.'], 413);
        }
        try {
            $payload = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !is_string($payload['label'] ?? null) || !is_array($payload['block'] ?? null)) {
                throw new \InvalidArgumentException('Ungültige Blockvorlage.');
            }
            $label = trim($payload['label']);
            $document = RichDocument::PREFIX.json_encode(['version' => 2, 'blocks' => [$payload['block']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $normalized = $this->policy->normalizeForStorage($document);
            $decoded = json_decode(substr($normalized, strlen(RichDocument::PREFIX)), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || !is_array($decoded['blocks'][0] ?? null)) {
                throw new \InvalidArgumentException('Ungültige Blockvorlage.');
            }
            /** @var array<string, mixed> $block */
            $block = $decoded['blocks'][0];
            $snippet = new NewsBlockSnippet($user, $label, $block);
            $created = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $manager) use ($user, $snippet): bool {
                $manager->refresh($user, LockMode::PESSIMISTIC_WRITE);
                if ($this->snippets->countForOwner($user) >= self::MAX_SNIPPETS) {
                    return false;
                }
                $manager->persist($snippet);
                $manager->flush();
                return true;
            });
            if (!$created) {
                return $this->privateJson(['error' => 'Die persönliche Bibliothek ist mit 50 Vorlagen vollständig.'], 409);
            }
            return $this->privateJson(['item' => [
                'id' => $snippet->getId(), 'label' => $snippet->getLabel(), 'block' => $snippet->getBlock(),
                'updatedAt' => $snippet->getUpdatedAt()->format(DATE_ATOM),
            ]], 201);
        } catch (\JsonException|\InvalidArgumentException $exception) {
            return $this->privateJson(['error' => $exception->getMessage()], 422);
        }
    }

    #[Route('/{id}', name: 'app_admin_news_editor_snippet_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $user = $this->user();
        if (!$this->isCsrfTokenValid('news-editor-snippets', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            throw $this->createAccessDeniedException('Ungültige Sicherheitsprüfung.');
        }
        $snippet = $this->snippets->find($id);
        if (!$snippet instanceof NewsBlockSnippet || $snippet->getOwner()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }
        $this->entityManager->remove($snippet);
        $this->entityManager->flush();
        return $this->privateJson(['deleted' => $id]);
    }

    private function user(): User
    {
        if (!$this->modules->isEnabled('content')) {
            throw $this->createNotFoundException();
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        return $user;
    }

    /** @param array<string, mixed> $data */
    private function privateJson(array $data, int $status = 200): JsonResponse
    {
        $response = $this->json($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        return $response;
    }
}

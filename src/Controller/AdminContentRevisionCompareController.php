<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContentEntry;
use App\Entity\ContentRevision;
use App\Entity\ContentTag;
use App\Module\CmsModuleManager;
use App\Repository\ContentRevisionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('CMS_CONTENT_MANAGE')]
final class AdminContentRevisionCompareController extends AbstractController
{
    public function __construct(
        private readonly ContentRevisionRepository $revisions,
        private readonly CmsModuleManager $modules,
    ) {
    }

    #[Route(
        '/admin/content/{id}/revisions/{revisionId}/compare',
        name: 'app_admin_content_revision_compare',
        requirements: ['id' => '\d+', 'revisionId' => '\d+'],
        methods: ['GET'],
    )]
    public function __invoke(ContentEntry $entry, int $revisionId): Response
    {
        if (!$this->modules->isEnabled('content')) {
            throw $this->createNotFoundException();
        }

        $revision = $this->revisions->find($revisionId);
        if (!$revision instanceof ContentRevision || $revision->getEntry()->getId() !== $entry->getId()) {
            throw $this->createNotFoundException();
        }

        $revisionTags = $revision->getTagSlugs();
        sort($revisionTags, SORT_STRING);
        $currentTags = array_map(
            static fn (ContentTag $tag): string => $tag->getSlug(),
            $entry->getTags()->toArray(),
        );
        sort($currentTags, SORT_STRING);

        $fields = [
            $this->field('Titel', $revision->getTitle(), $entry->getTitle()),
            $this->field('Untertitel', $revision->getSubtitle(), $entry->getSubtitle()),
            $this->field('Slug', $revision->getSlug(), $entry->getSlug()),
            $this->field('Kurzbeschreibung', $revision->getExcerpt(), $entry->getExcerpt()),
            $this->field('Status', $this->statusLabel($revision->getStatus()), $this->statusLabel($entry->getStatus())),
            $this->field('Kategorie', $revision->getCategory()?->getDisplayName(), $entry->getCategory()?->getDisplayName()),
            $this->field('Tags', implode(', ', $revisionTags), implode(', ', $currentTags)),
        ];

        $revisionBody = $revision->getEditorDocument() ?? $revision->getBody();
        $currentBody = $entry->getEditableDocument();
        $response = $this->render('admin/content/revision_compare.html.twig', [
            'entry' => $entry,
            'revision' => $revision,
            'fields' => $fields,
            'revisionBody' => $revisionBody,
            'currentBody' => $currentBody,
            'bodyChanged' => $revisionBody !== $currentBody,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    /**
     * @return array{label: string, revision: string, current: string, changed: bool}
     */
    private function field(string $label, ?string $revision, ?string $current): array
    {
        return [
            'label' => $label,
            'revision' => $revision === null || $revision === '' ? '—' : $revision,
            'current' => $current === null || $current === '' ? '—' : $current,
            'changed' => $revision !== $current,
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            ContentEntry::STATUS_DRAFT => 'Entwurf',
            ContentEntry::STATUS_REVIEW => 'In Prüfung',
            ContentEntry::STATUS_SCHEDULED => 'Geplant',
            ContentEntry::STATUS_PUBLISHED => 'Veröffentlicht',
            ContentEntry::STATUS_ARCHIVED => 'Archiviert',
            ContentEntry::STATUS_TRASHED => 'Papierkorb',
            default => $status,
        };
    }
}

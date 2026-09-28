<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ContentEntry;
use App\Entity\ContentRevision;
use App\Entity\User;
use App\Repository\ContentRevisionRepository;
use App\Repository\ContentTagRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ContentRevisionManager
{
    public function __construct(
        private ContentRevisionRepository $revisions,
        private ContentTagRepository $tags,
        private EntityManagerInterface $entityManager,
    ) {}

    public function capture(ContentEntry $entry, User $user): ContentRevision
    {
        if ($entry->getId() === null) { throw new \DomainException('Content must be persisted before revision capture.'); }
        $revision = new ContentRevision($entry, $this->revisions->nextNumber($entry), $user);
        $this->entityManager->persist($revision);
        return $revision;
    }

    public function restore(ContentEntry $entry, ContentRevision $revision, User $user): void
    {
        if ($revision->getEntry() !== $entry) {
            throw new \DomainException('Revision belongs to another content entry.');
        }

        $this->capture($entry, $user);
        $revision->restoreTo($entry);
        $entry->clearTags();
        foreach ($revision->getTagSlugs() as $slug) {
            $tag = $this->tags->findOneBySlug($slug);
            if ($tag !== null) { $entry->addTag($tag); }
        }
        $entry->setStatus(ContentEntry::STATUS_DRAFT)->setPublishedAt(null)->setScheduledAt(null)->setScheduledUnpublishAt(null);
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ContentEntry;
use App\Entity\ContentRevision;
use App\Entity\User;
use App\Repository\ContentRevisionRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ContentRevisionManager
{
    public function __construct(
        private ContentRevisionRepository $revisions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function capture(ContentEntry $entry, User $user): ContentRevision
    {
        if ($entry->getId() === null) {
            throw new \DomainException('Content must be persisted before revision capture.');
        }

        $revision = new ContentRevision($entry, $this->revisions->nextNumber($entry), $user);
        $this->entityManager->persist($revision);

        return $revision;
    }

    public function restore(ContentEntry $entry, ContentRevision $revision, User $user): void
    {
        $this->capture($entry, $user);
        $revision->restoreTo($entry);
        $entry->setStatus(ContentEntry::STATUS_DRAFT)->setPublishedAt(null)->setScheduledAt(null);
    }
}

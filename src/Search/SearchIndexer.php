<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Search\SearchDocument;
use App\Repository\Search\SearchDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SearchIndexer
{
    public function __construct(
        private SearchIndexAdapterRegistry $adapters,
        private SearchDocumentRepository $documents,
        private LocalSearchVisibilityBoundary $visibility,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function rebuild(): SearchIndexReport
    {
        $records = [];
        $sourceTypes = [];
        $skipped = 0;

        foreach ($this->adapters->all() as $adapter) {
            $sourceTypes = array_merge($sourceTypes, $adapter->sourceTypes());
            foreach ($adapter->records() as $record) {
                if (!$this->visibility->canIndex($record)) {
                    ++$skipped;
                    continue;
                }
                $key = $record->sourceType.'#'.$record->sourceId;
                if (isset($records[$key])) {
                    throw new \LogicException('Duplicate source in search index plan: '.$key);
                }
                $records[$key] = $record;
            }
        }

        $existing = [];
        foreach ($this->documents->findForIndex(array_values(array_unique($sourceTypes))) as $document) {
            $existing[$document->getSourceType().'#'.$document->getSourceId()] = $document;
        }

        $created = 0;
        $updated = 0;
        foreach ($records as $key => $record) {
            $document = $existing[$key] ?? null;
            if (!$document instanceof SearchDocument) {
                $this->entityManager->persist(new SearchDocument($record));
                ++$created;
                continue;
            }
            if ($document->getFingerprint() !== $record->fingerprint()) {
                $document->updateFrom($record);
                ++$updated;
            }
            unset($existing[$key]);
        }

        foreach ($existing as $document) {
            $this->entityManager->remove($document);
        }
        $this->entityManager->flush();

        return new SearchIndexReport($created, $updated, count($existing), $skipped, new \DateTimeImmutable());
    }

    public function repairStaleDocuments(): SearchIndexReport
    {
        return $this->rebuild();
    }
}

<?php

declare(strict_types=1);

namespace App\Media;

use App\ContentEditor\ContentBlockDocument;
use App\Entity\ContentEntry;
use App\Entity\MediaAsset;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MediaAssetUsageBrowser
{
    private const MAX_REFERENCES = 50;
    private const MAX_CONTENT_CANDIDATES = 200;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContentBlockDocument $contentDocuments,
    ) {
    }

    /**
     * @return array{items: list<array{id: int, title: string, type: string, status: string, areas: list<string>}>, truncated: bool}
     */
    public function contentReferences(MediaAsset $asset): array
    {
        $location = $asset->getLocation();
        $assetId = $asset->getId();
        if ($location === '' && $assetId === null) {
            return ['items' => [], 'truncated' => false];
        }

        $conditions = [];
        $parameters = [];
        if ($location !== '') {
            $conditions[] = 'LOCATE(:location, entry.body) > 0';
            $conditions[] = 'LOCATE(:location, entry.excerpt) > 0';
            $parameters['location'] = $location;
        }
        if ($assetId !== null) {
            $conditions[] = 'LOCATE(:mediaMarker, entry.editorDocument) > 0';
            $parameters['mediaMarker'] = '"assetId":'.$assetId.',';
        }
        if ($conditions === []) {
            return ['items' => [], 'truncated' => false];
        }

        $query = $this->entityManager->getRepository(ContentEntry::class)->createQueryBuilder('entry')
            ->andWhere('('.implode(' OR ', $conditions).')')
            ->setParameters($parameters)
            ->orderBy('entry.id', 'ASC')
            ->setMaxResults(self::MAX_CONTENT_CANDIDATES + 1)
            ->getQuery();

        /** @var list<ContentEntry> $entries */
        $entries = $query->getResult();
        $truncated = count($entries) > self::MAX_CONTENT_CANDIDATES;
        if ($truncated) {
            array_pop($entries);
        }

        $items = [];
        foreach ($entries as $entry) {
            $id = $entry->getId();
            if ($id === null) {
                continue;
            }

            $areas = [];
            if ($location !== '' && $entry->getExcerpt() !== null && str_contains($entry->getExcerpt(), $location)) {
                $areas[] = 'Kurztext';
            }
            if ($location !== '' && str_contains($entry->getBody(), $location)) {
                $areas[] = 'Inhalt';
            }
            if ($assetId !== null && $this->hasImageBlock($entry, $assetId)) {
                $areas[] = 'Bildblock';
            }
            if ($areas === []) {
                continue;
            }

            $items[] = [
                'id' => $id,
                'title' => $entry->getTitle(),
                'type' => $entry->getType(),
                'status' => $entry->getStatus(),
                'areas' => $areas,
            ];
        }

        if (count($items) > self::MAX_REFERENCES) {
            $items = array_slice($items, 0, self::MAX_REFERENCES);
            $truncated = true;
        }

        return ['items' => $items, 'truncated' => $truncated];
    }

    /**
     * @return array{items: list<array{context: string, label: string}>, truncated: bool}
     */
    public function layoutReferences(MediaAsset $asset, bool $showPageTitles): array
    {
        $assetId = $asset->getId();
        if ($assetId === null) {
            return ['items' => [], 'truncated' => false];
        }

        $contexts = $this->layoutContextsForAsset($assetId);
        $truncated = count($contexts) > self::MAX_REFERENCES;
        if ($truncated) {
            array_pop($contexts);
        }

        $items = [];
        foreach ($contexts as $context) {
            if ($context === 'home') {
                $items[] = ['context' => $context, 'label' => 'Startseite'];

                continue;
            }

            if (preg_match('/\Apage-([1-9][0-9]*)\z/D', $context, $matches) !== 1) {
                continue;
            }

            $pageId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($pageId)) {
                continue;
            }

            $entry = $this->entityManager->find(ContentEntry::class, $pageId);
            if (!$entry instanceof ContentEntry || $entry->getType() !== ContentEntry::TYPE_PAGE) {
                continue;
            }

            $items[] = [
                'context' => $context,
                'label' => $showPageTitles ? 'Seitenlayout: '.$entry->getTitle() : 'Seitenlayout',
            ];
        }

        return ['items' => $items, 'truncated' => $truncated];
    }

    /** @return list<string> */
    private function layoutContextsForAsset(int $assetId): array
    {
        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();
        $table = $platform->quoteIdentifier('page_layout');
        $documentColumn = $platform->quoteIdentifier('document');
        $contextColumn = $platform->quoteIdentifier('context');
        $needle = json_encode(['widgets' => [['config' => ['imageId' => $assetId]]]], JSON_THROW_ON_ERROR);

        $parameters = [];
        $platformName = $platform->getName();
        if ($platformName === 'postgresql') {
            $predicate = 'CAST(pl.'.$documentColumn.' AS JSONB) @> CAST(:needle AS JSONB)';
            $parameters['needle'] = $needle;
        } elseif ($platformName === 'sqlite') {
            $predicate = "EXISTS (SELECT 1 FROM json_each(pl.".$documentColumn.", '$.widgets') AS widget WHERE json_extract(widget.value, '$.config.imageId') = :assetId)";
            $parameters['assetId'] = $assetId;
        } elseif (in_array($platformName, ['mysql', 'mariadb'], true)) {
            $predicate = 'JSON_CONTAINS(pl.'.$documentColumn.', :needle, \'$\')';
            $parameters['needle'] = $needle;
        } else {
            throw new \LogicException('The media usage browser requires a database with JSON containment support.');
        }

        $sql = 'SELECT pl.'.$contextColumn.' FROM '.$table.' AS pl WHERE '.$predicate
            .' ORDER BY pl.'.$contextColumn.' ASC LIMIT '.(self::MAX_REFERENCES + 1);
        $values = $connection->executeQuery($sql, $parameters)->fetchFirstColumn();

        $contexts = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $contexts[] = $value;
            }
        }

        return $contexts;
    }

    private function hasImageBlock(ContentEntry $entry, int $assetId): bool
    {
        $document = $entry->getEditorDocument();
        if ($document === null) {
            return false;
        }

        try {
            $decoded = $this->contentDocuments->decode($document);
        } catch (\InvalidArgumentException) {
            return false;
        }

        foreach ($decoded['blocks'] as $block) {
            if (($block['type'] ?? null) === 'media' && ($block['assetId'] ?? null) === $assetId) {
                return true;
            }
        }

        return false;
    }
}

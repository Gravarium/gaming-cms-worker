<?php

declare(strict_types=1);

namespace App\Media;

use App\ContentEditor\ContentBlockDocument;
use App\Entity\ContentEntry;
use App\Entity\MediaAsset;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
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
     * @return array{items: list<array{id: int, title: string, type: string, status: string, areas: list<string>}>, hasMore: bool, nextCursor: ?string}
     */
    public function contentReferences(MediaAsset $asset, ?string $cursor = null): array
    {
        $location = $asset->getLocation();
        $assetId = $asset->getId();
        $afterId = $this->decodeContentCursor($cursor, $assetId);
        if ($location === '' && $assetId === null) {
            return ['items' => [], 'hasMore' => false, 'nextCursor' => null];
        }

        $conditions = [];
        $parameters = [];
        if ($location !== '') {
            $conditions[] = 'LOCATE(:location, entry.body) > 0';
            $conditions[] = 'LOCATE(:location, entry.excerpt) > 0';
            $parameters['location'] = $location;
        }
        if ($assetId !== null) {
            $conditions[] = '(LOCATE(:mediaMarker, entry.editorDocument) > 0 OR LOCATE(:mediaMarkerAtEnd, entry.editorDocument) > 0)';
            $parameters['mediaMarker'] = '"assetId":'.$assetId.',';
            $parameters['mediaMarkerAtEnd'] = '"assetId":'.$assetId.'}';
        }
        $queryBuilder = $this->entityManager->getRepository(ContentEntry::class)->createQueryBuilder('entry')
            ->andWhere('('.implode(' OR ', $conditions).')')
            ->orderBy('entry.id', 'ASC')
            ->setMaxResults(self::MAX_CONTENT_CANDIDATES + 1);
        if ($afterId !== null) {
            $queryBuilder->andWhere('entry.id > :afterId')->setParameter('afterId', $afterId);
        }
        foreach ($parameters as $name => $value) {
            $queryBuilder->setParameter($name, $value);
        }

        /** @var list<ContentEntry> $entries */
        $entries = $queryBuilder->getQuery()->getResult();
        $hasMoreCandidates = count($entries) > self::MAX_CONTENT_CANDIDATES;
        $entries = array_slice($entries, 0, self::MAX_CONTENT_CANDIDATES);

        $items = [];
        $lastScannedId = null;
        $lastIncludedId = null;
        $hasMoreReferences = false;
        foreach ($entries as $entry) {
            $id = $entry->getId();
            if ($id === null) {
                continue;
            }
            $lastScannedId = $id;

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

            if (count($items) === self::MAX_REFERENCES) {
                $hasMoreReferences = true;

                break;
            }

            $items[] = [
                'id' => $id,
                'title' => $entry->getTitle(),
                'type' => $entry->getType(),
                'status' => $entry->getStatus(),
                'areas' => $areas,
            ];
            $lastIncludedId = $id;
        }

        $hasMore = $hasMoreReferences || $hasMoreCandidates;
        $cursorId = $hasMoreReferences ? $lastIncludedId : ($hasMoreCandidates ? $lastScannedId : null);
        if ($cursorId === null || $assetId === null) {
            $hasMore = false;
        }
        $nextCursor = null;
        if ($hasMore && $assetId !== null && $cursorId !== null) {
            $nextCursor = $this->encodeContentCursor($assetId, $cursorId);
        }

        return [
            'items' => $items,
            'hasMore' => $hasMore,
            'nextCursor' => $nextCursor,
        ];
    }

    private function encodeContentCursor(int $assetId, int $afterId): string
    {
        return rtrim(strtr(base64_encode($assetId.':'.$afterId), '+/', '-_'), '=');
    }

    private function decodeContentCursor(?string $cursor, ?int $assetId): ?int
    {
        if ($cursor === null) {
            return null;
        }
        if ($assetId === null || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $cursor) !== 1) {
            throw new \InvalidArgumentException('Invalid media usage cursor.');
        }

        $encoded = strtr($cursor, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded) || preg_match('/\A([1-9][0-9]*):([1-9][0-9]*)\z/D', $decoded, $matches) !== 1) {
            throw new \InvalidArgumentException('Invalid media usage cursor.');
        }

        $cursorAssetId = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $afterId = filter_var($matches[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($cursorAssetId) || !is_int($afterId) || $cursorAssetId !== $assetId) {
            throw new \InvalidArgumentException('Invalid media usage cursor.');
        }

        return $afterId;
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
        $table = $platform->quoteSingleIdentifier('page_layout');
        $documentColumn = $platform->quoteSingleIdentifier('document');
        $contextColumn = $platform->quoteSingleIdentifier('context');
        $needle = json_encode(['widgets' => [['config' => ['imageId' => $assetId]]]], JSON_THROW_ON_ERROR);

        $parameters = [];
        $types = [];
        if ($platform instanceof PostgreSQLPlatform) {
            $predicate = 'CAST(pl.'.$documentColumn.' AS JSONB) @> CAST(:needle AS JSONB)';
            $parameters['needle'] = $needle;
        } elseif ($platform instanceof SQLitePlatform) {
            $predicate = "EXISTS (SELECT 1 FROM json_each(pl.".$documentColumn.", '$.widgets') AS widget WHERE json_extract(widget.value, '$.config.imageId') = :assetId)";
            $parameters['assetId'] = $assetId;
            $types['assetId'] = ParameterType::INTEGER;
        } elseif ($platform instanceof AbstractMySQLPlatform) {
            $predicate = 'JSON_CONTAINS(pl.'.$documentColumn.', :needle, \'$\')';
            $parameters['needle'] = $needle;
        } else {
            throw new \LogicException('The media usage browser requires a database with JSON containment support.');
        }

        $sql = 'SELECT pl.'.$contextColumn.' FROM '.$table.' AS pl WHERE '.$predicate
            .' ORDER BY pl.'.$contextColumn.' ASC LIMIT '.(self::MAX_REFERENCES + 1);
        $values = $connection->executeQuery($sql, $parameters, $types)->fetchFirstColumn();

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

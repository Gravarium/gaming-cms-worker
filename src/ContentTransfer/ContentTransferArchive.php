<?php

declare(strict_types=1);

namespace App\ContentTransfer;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class ContentTransferArchive
{
    public const FORMAT = 'gaming-cms-content';
    public const VERSION = 1;
    public const MAX_ENTRIES = 20;
    public const MAX_BUNDLE_BYTES = 8000000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
        private AuditLogger $audit,
    ) {
    }

    /** @param list<int> $entryIds */
    public function export(array $entryIds): string
    {
        if ($entryIds === [] || count($entryIds) > self::MAX_ENTRIES) {
            throw new \InvalidArgumentException('Die Auswahl ist ungültig.');
        }

        $seenIds = [];
        foreach ($entryIds as $id) {
            if ($id < 1 || isset($seenIds[$id])) {
                throw new \InvalidArgumentException('Die Auswahl ist ungültig.');
            }
            $seenIds[$id] = true;
        }
        sort($entryIds, SORT_NUMERIC);

        /** @var list<ContentEntry> $entries */
        $entries = $this->entityManager->getRepository(ContentEntry::class)->findBy(
            ['id' => $entryIds],
            ['id' => 'ASC'],
            self::MAX_ENTRIES,
        );
        if (count($entries) !== count($entryIds)) {
            throw new \InvalidArgumentException('Die Auswahl enthält nicht verfügbare Inhalte.');
        }

        $bundleEntries = [];
        foreach ($entries as $entry) {
            if ($entry->getStatus() === ContentEntry::STATUS_TRASHED) {
                throw new \InvalidArgumentException('Die Auswahl enthält nicht verfügbare Inhalte.');
            }

            $tagSlugs = array_values(array_map(
                static fn (ContentTag $tag): string => $tag->getSlug(),
                $entry->getTags()->toArray(),
            ));
            sort($tagSlugs, SORT_STRING);

            $bundleEntries[] = [
                'type' => $entry->getType(),
                'title' => $entry->getTitle(),
                'subtitle' => $entry->getSubtitle(),
                'slug' => $entry->getSlug(),
                'excerpt' => $entry->getExcerpt(),
                'body' => $entry->getBody(),
                'categorySlug' => $entry->getCategory()?->getSlug(),
                'tagSlugs' => $tagSlugs,
                'seoTitle' => $entry->getSeoTitle(),
                'seoDescription' => $entry->getSeoDescription(),
                'canonicalUrl' => $entry->getCanonicalUrl(),
                'noIndex' => $entry->isNoIndex(),
            ];
        }

        try {
            $json = json_encode([
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'entries' => $bundleEntries,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Die Inhalte können nicht als Bundle exportiert werden.');
        }

        $json .= "\n";
        if (strlen($json) > self::MAX_BUNDLE_BYTES) {
            throw new \InvalidArgumentException('Die Auswahl überschreitet die maximale Bundlegröße.');
        }

        $this->audit->record(
            'content.transfer.export',
            ContentEntry::class,
            null,
            'CMS-Inhalte als portables Bundle exportiert.',
            ['count' => count($entries), 'version' => self::VERSION],
        );
        $this->entityManager->flush();

        return $json;
    }

    public function import(string $json, User $author): int
    {
        if (strlen($json) > self::MAX_BUNDLE_BYTES) {
            throw new \InvalidArgumentException('Das Bundle überschreitet die maximale Dateigröße.');
        }

        try {
            $bundle = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Das Bundle ist kein gültiges JSON.');
        }

        if (!is_array($bundle) || array_is_list($bundle)) {
            throw new \InvalidArgumentException('Das Bundle hat ein ungültiges Format.');
        }
        $this->assertKeys($bundle, ['format', 'version', 'entries']);
        if (($bundle['format'] ?? null) !== self::FORMAT || ($bundle['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('Das Bundle-Format oder die Version wird nicht unterstützt.');
        }
        $rawEntries = $bundle['entries'] ?? null;
        if (!is_array($rawEntries) || !array_is_list($rawEntries) || $rawEntries === [] || count($rawEntries) > self::MAX_ENTRIES) {
            throw new \InvalidArgumentException('Die Anzahl der Bundle-Inhalte ist ungültig.');
        }

        $reservedSlugs = [];
        $prepared = [];
        foreach ($rawEntries as $rawEntry) {
            if (!is_array($rawEntry) || array_is_list($rawEntry)) {
                throw new \InvalidArgumentException('Ein Bundle-Inhalt hat ein ungültiges Format.');
            }

            $prepared[] = $this->prepareEntry($rawEntry, $author, $reservedSlugs);
        }

        $count = count($prepared);
        $result = $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($prepared, $count): int {
            foreach ($prepared as $entry) {
                $entityManager->persist($entry);
            }
            $this->audit->record(
                'content.transfer.import',
                ContentEntry::class,
                null,
                'CMS-Inhalte als private Entwürfe importiert.',
                ['count' => $count, 'version' => self::VERSION],
            );

            return $count;
        });
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, true> $reservedSlugs
     */
    private function prepareEntry(array $data, User $author, array &$reservedSlugs): ContentEntry
    {
        $allowed = [
            'type', 'title', 'subtitle', 'slug', 'excerpt', 'body',
            'categorySlug', 'tagSlugs', 'seoTitle', 'seoDescription', 'canonicalUrl', 'noIndex',
        ];
        $this->assertKeys($data, $allowed);

        $type = $data['type'] ?? null;
        if (!is_string($type) || !in_array($type, [ContentEntry::TYPE_NEWS, ContentEntry::TYPE_PAGE], true)) {
            throw new \InvalidArgumentException('Ein Bundle-Inhalt hat einen ungültigen Typ.');
        }

        $title = $this->requiredText($data, 'title', 180);
        $body = $this->requiredText($data, 'body', 60000);
        if (trim($title) === '' || trim($body) === '') {
            throw new \InvalidArgumentException('Titel und Inhalt dürfen nicht leer sein.');
        }

        $rawSlug = $this->requiredText($data, 'slug', 200);
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $rawSlug) !== 1) {
            throw new \InvalidArgumentException('Ein Bundle-Inhalt hat einen ungültigen Slug.');
        }

        $subtitle = $this->optionalText($data, 'subtitle', 220);
        $excerpt = $this->optionalText($data, 'excerpt', 500);
        $seoTitle = $this->optionalText($data, 'seoTitle', 180);
        $seoDescription = $this->optionalText($data, 'seoDescription', 320);
        $canonicalUrl = $this->optionalText($data, 'canonicalUrl', 500);
        $noIndex = $data['noIndex'] ?? true;
        if (!is_bool($noIndex)) {
            throw new \InvalidArgumentException('Ein Bundle-Inhalt hat ungültige SEO-Metadaten.');
        }

        $category = $this->resolveCategory($data['categorySlug'] ?? null);
        $tags = $this->resolveTags($data['tagSlugs'] ?? []);
        $slug = $this->uniqueSlug($rawSlug, $reservedSlugs);

        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType($type)
            ->setTitle($title)
            ->setSubtitle($subtitle)
            ->setSlug($slug)
            ->setExcerpt($excerpt)
            ->setBody($body)
            ->setCategory($category)
            ->setSeoTitle($seoTitle)
            ->setSeoDescription($seoDescription)
            ->setCanonicalUrl($canonicalUrl)
            ->setNoIndex($noIndex)
            ->setStatus(ContentEntry::STATUS_DRAFT)
            ->setPublishedAt(null)
            ->setScheduledAt(null)
            ->setScheduledUnpublishAt(null)
            ->setFeatured(false)
            ->setPinned(false)
            ->setUnlisted(false);
        foreach ($tags as $tag) {
            $entry->addTag($tag);
        }
        $entry->synchronizePublication();

        if (count($this->validator->validate($entry)) > 0) {
            throw new \InvalidArgumentException('Ein Bundle-Inhalt verletzt die CMS-Validierungsregeln.');
        }

        return $entry;
    }

    /** @param array<string, mixed> $data */
    private function requiredText(array $data, string $key, int $maxLength): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new \InvalidArgumentException('Ein Bundle-Inhalt enthält ungültige Textdaten.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function optionalText(array $data, string $key, int $maxLength): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maxLength)) {
            throw new \InvalidArgumentException('Ein Bundle-Inhalt enthält ungültige Textdaten.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data
     * @param list<string> $allowed
     */
    private function assertKeys(array $data, array $allowed): void
    {
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('Das Bundle enthält nicht unterstützte Felder.');
            }
        }
    }

    private function resolveCategory(mixed $rawSlug): ?Category
    {
        if ($rawSlug === null) {
            return null;
        }
        if (!is_string($rawSlug) || strlen($rawSlug) > 120 || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $rawSlug) !== 1) {
            throw new \InvalidArgumentException('Das Bundle verweist auf eine ungültige Kategorie.');
        }

        $category = $this->entityManager->getRepository(Category::class)->findOneBy(['slug' => $rawSlug]);
        if (!$category instanceof Category) {
            throw new \InvalidArgumentException('Das Bundle verweist auf eine nicht vorhandene Kategorie.');
        }

        return $category;
    }

    /** @return list<ContentTag> */
    private function resolveTags(mixed $rawSlugs): array
    {
        if (!is_array($rawSlugs) || !array_is_list($rawSlugs) || count($rawSlugs) > 30) {
            throw new \InvalidArgumentException('Die Bundle-Schlagwörter sind ungültig.');
        }

        $tags = [];
        $seen = [];
        foreach ($rawSlugs as $rawSlug) {
            if (!is_string($rawSlug) || strlen($rawSlug) > 120 || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $rawSlug) !== 1 || isset($seen[$rawSlug])) {
                throw new \InvalidArgumentException('Die Bundle-Schlagwörter sind ungültig.');
            }
            $seen[$rawSlug] = true;
            $tag = $this->entityManager->getRepository(ContentTag::class)->findOneBy(['slug' => $rawSlug]);
            if (!$tag instanceof ContentTag) {
                throw new \InvalidArgumentException('Das Bundle verweist auf ein nicht vorhandenes Schlagwort.');
            }
            $tags[] = $tag;
        }

        return $tags;
    }

    /**
     * @param array<string, true> $reservedSlugs
     */
    private function uniqueSlug(string $base, array &$reservedSlugs): string
    {
        $candidate = $base;
        $sequence = 2;
        while (isset($reservedSlugs[$candidate]) || $this->entityManager->getRepository(ContentEntry::class)->findOneBy(['slug' => $candidate]) instanceof ContentEntry) {
            $suffix = '-import-'.$sequence++;
            $candidate = rtrim(substr($base, 0, 200 - strlen($suffix)), '-').$suffix;
        }
        $reservedSlugs[$candidate] = true;

        return $candidate;
    }
}

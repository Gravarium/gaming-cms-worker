<?php

declare(strict_types=1);

namespace App\Widget\ContentRelease;

use App\Entity\ContentEntry;
use App\Entity\ContentRelease;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class PublicContentReleaseQuery
{
    public const DEFAULT_LIMIT = 6;
    public const MAX_RELEASES = 12;
    public const MAX_ENTRIES_PER_RELEASE = 3;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @phpstan-type PublicEntry array{id:int,title:string,slug:string,type:string,excerpt:?string,publishedAt:\DateTimeImmutable}
     * @phpstan-type PublicRelease array{id:int,name:string,description:?string,publishedAt:\DateTimeImmutable,entries:list<PublicEntry>}
     *
     * @return list<PublicRelease>
     */
    public function findPublic(int $limit = self::DEFAULT_LIMIT, ?\DateTimeImmutable $now = null): array
    {
        $limit = max(1, min(self::MAX_RELEASES, $limit));
        $now ??= new \DateTimeImmutable();

        $releaseRows = $this->entityManager->createQueryBuilder()
            ->select('release.id AS releaseId')
            ->from(ContentRelease::class, 'release')
            ->innerJoin('release.entries', 'entry')
            ->groupBy('release.id')
            ->addGroupBy('release.publishedAt')
            ->orderBy('release.publishedAt', 'DESC')
            ->addOrderBy('release.id', 'DESC')
            ->setMaxResults($limit);

        $this->restrictToPublicReleasesAndEntries($releaseRows, $now);

        /** @var list<array<string, mixed>> $releaseIds */
        $releaseIds = $releaseRows->getQuery()->getScalarResult();
        $releases = [];

        foreach ($releaseIds as $releaseRow) {
            $releaseId = $this->positiveInteger($releaseRow['releaseId'] ?? null);
            if ($releaseId === null) {
                continue;
            }

            $entryRows = $this->entityManager->createQueryBuilder()
                ->select(
                    'release.id AS releaseId',
                    'release.name AS releaseName',
                    'release.description AS releaseDescription',
                    'release.publishedAt AS releasePublishedAt',
                    'entry.id AS entryId',
                    'entry.title AS entryTitle',
                    'entry.slug AS entrySlug',
                    'entry.type AS entryType',
                    'entry.excerpt AS entryExcerpt',
                    'entry.publishedAt AS entryPublishedAt',
                )
                ->from(ContentRelease::class, 'release')
                ->innerJoin('release.entries', 'entry')
                ->andWhere('release.id = :releaseId')
                ->setParameter('releaseId', $releaseId)
                ->orderBy('entry.publishedAt', 'DESC')
                ->addOrderBy('entry.id', 'DESC')
                ->setMaxResults(self::MAX_ENTRIES_PER_RELEASE);

            $this->restrictToPublicReleasesAndEntries($entryRows, $now);

            /** @var list<array<string, mixed>> $rows */
            $rows = $entryRows->getQuery()->getScalarResult();
            if ($rows === []) {
                continue;
            }

            $releaseName = $rows[0]['releaseName'] ?? null;
            $releaseDescription = $this->nullableString($rows[0]['releaseDescription'] ?? null);
            $releasePublishedAt = $this->immutableDateTime($rows[0]['releasePublishedAt'] ?? null);
            if (!is_string($releaseName) || $releasePublishedAt === null) {
                continue;
            }

            $entries = [];
            foreach ($rows as $row) {
                $entryId = $this->positiveInteger($row['entryId'] ?? null);
                $entryTitle = $row['entryTitle'] ?? null;
                $entrySlug = $row['entrySlug'] ?? null;
                $entryType = $row['entryType'] ?? null;
                $entryPublishedAt = $this->immutableDateTime($row['entryPublishedAt'] ?? null);

                if (
                    $entryId === null
                    || !is_string($entryTitle)
                    || !is_string($entrySlug)
                    || !is_string($entryType)
                    || !in_array($entryType, [ContentEntry::TYPE_NEWS, ContentEntry::TYPE_PAGE], true)
                    || $entryPublishedAt === null
                ) {
                    continue;
                }

                $entries[] = [
                    'id' => $entryId,
                    'title' => $entryTitle,
                    'slug' => $entrySlug,
                    'type' => $entryType,
                    'excerpt' => $this->nullableString($row['entryExcerpt'] ?? null),
                    'publishedAt' => $entryPublishedAt,
                ];
            }

            if ($entries === []) {
                continue;
            }

            $releases[] = [
                'id' => $releaseId,
                'name' => $releaseName,
                'description' => $releaseDescription,
                'publishedAt' => $releasePublishedAt,
                'entries' => $entries,
            ];
        }

        return $releases;
    }

    private function restrictToPublicReleasesAndEntries(QueryBuilder $query, \DateTimeImmutable $now): void
    {
        $query
            ->andWhere('release.status = :releaseStatus')
            ->andWhere('release.publishedAt IS NOT NULL')
            ->andWhere('release.publishedAt <= :now')
            ->andWhere('entry.type IN (:entryTypes)')
            ->andWhere('entry.status = :entryStatus')
            ->andWhere('entry.publishedAt IS NOT NULL')
            ->andWhere('entry.publishedAt <= :now')
            ->andWhere('entry.unlisted = false')
            ->andWhere('(entry.scheduledUnpublishAt IS NULL OR entry.scheduledUnpublishAt > :now)')
            ->setParameter('releaseStatus', ContentRelease::STATUS_PUBLISHED)
            ->setParameter('entryTypes', [ContentEntry::TYPE_NEWS, ContentEntry::TYPE_PAGE])
            ->setParameter('entryStatus', ContentEntry::STATUS_PUBLISHED)
            ->setParameter('now', $now);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $integer = (int) $value;

            return $integer > 0 ? $integer : null;
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function immutableDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value)) {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
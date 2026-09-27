<?php

declare(strict_types=1);

namespace App\ContentRelease;

use App\Entity\ContentRelease;
use App\Repository\ContentReleaseRepository;
use Doctrine\ORM\QueryBuilder;

final readonly class AdminContentReleaseBrowser
{
    public const PAGE_SIZE = 25;

    private const MAX_SEARCH_LENGTH = 100;

    /** @var list<array{value: string, label: string}> */
    private const STATUS_OPTIONS = [
        ['value' => ContentRelease::STATUS_DRAFT, 'label' => 'Entwurf'],
        ['value' => ContentRelease::STATUS_SCHEDULED, 'label' => 'Geplant'],
        ['value' => ContentRelease::STATUS_PUBLISHED, 'label' => 'Veröffentlicht'],
        ['value' => ContentRelease::STATUS_CANCELLED, 'label' => 'Abgebrochen'],
    ];

    public function __construct(private ContentReleaseRepository $releases)
    {
    }

    /**
     * @return array{
     *     releases: list<array{id: int, name: string, status: string, entryCount: int, scheduledAt: ?\DateTimeImmutable, publishedAt: ?\DateTimeImmutable}>,
     *     total: int,
     *     page: int,
     *     pageCount: int,
     *     first: int,
     *     last: int,
     *     search: string,
     *     status: string,
     *     statusOptions: list<array{value: string, label: string}>
     * }
     */
    public function read(mixed $searchInput = null, mixed $statusInput = null, mixed $pageInput = null): array
    {
        $search = self::parseSearch($searchInput);
        $status = self::parseStatus($statusInput);
        $requestedPage = self::parsePage($pageInput);

        $countBuilder = $this->releases->createQueryBuilder('release');
        $this->applyFilters($countBuilder, $search, $status);
        $total = (int) $countBuilder->select('COUNT(release.id)')->getQuery()->getSingleScalarResult();
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($requestedPage, $pageCount);
        $offset = ($page - 1) * self::PAGE_SIZE;

        $queryBuilder = $this->releases->createQueryBuilder('release');
        $this->applyFilters($queryBuilder, $search, $status);
        $queryBuilder
            ->select(
                'release.id AS id',
                'release.name AS name',
                'release.status AS status',
                'release.scheduledAt AS scheduledAt',
                'release.publishedAt AS publishedAt',
                'COUNT(entry.id) AS entryCount',
            )
            ->leftJoin('release.entries', 'entry')
            ->groupBy('release.id')
            ->orderBy('release.createdAt', 'DESC')
            ->addOrderBy('release.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults(self::PAGE_SIZE);

        /** @var list<array{id: mixed, name: mixed, status: mixed, scheduledAt: mixed, publishedAt: mixed, entryCount: mixed}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();
        $releases = [];
        foreach ($rows as $row) {
            $releases[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'status' => (string) $row['status'],
                'entryCount' => max(0, (int) $row['entryCount']),
                'scheduledAt' => self::dateTime($row['scheduledAt']),
                'publishedAt' => self::dateTime($row['publishedAt']),
            ];
        }

        $first = $releases === [] ? 0 : $offset + 1;

        return [
            'releases' => $releases,
            'total' => $total,
            'page' => $page,
            'pageCount' => $pageCount,
            'first' => $first,
            'last' => $releases === [] ? 0 : $offset + count($releases),
            'search' => $search,
            'status' => $status,
            'statusOptions' => self::STATUS_OPTIONS,
        ];
    }

    private function applyFilters(QueryBuilder $queryBuilder, string $search, string $status): void
    {
        if ($search !== '') {
            $queryBuilder
                ->andWhere('LOWER(release.name) LIKE :releaseSearch')
                ->setParameter('releaseSearch', '%'.mb_strtolower($search).'%');
        }

        if ($status !== '') {
            $queryBuilder
                ->andWhere('release.status = :releaseStatus')
                ->setParameter('releaseStatus', $status);
        }
    }

    private static function parseSearch(mixed $searchInput): string
    {
        if ($searchInput === null) {
            return '';
        }

        if (!is_string($searchInput)) {
            throw new \InvalidArgumentException('The release search input is invalid.');
        }

        $search = trim($searchInput);
        if (mb_strlen($search) > self::MAX_SEARCH_LENGTH) {
            throw new \InvalidArgumentException('The release search input is too long.');
        }

        return $search;
    }

    private static function parseStatus(mixed $statusInput): string
    {
        if ($statusInput === null || $statusInput === '') {
            return '';
        }

        if (!is_string($statusInput)) {
            throw new \InvalidArgumentException('The release status is invalid.');
        }

        foreach (self::STATUS_OPTIONS as $option) {
            if ($statusInput === $option['value']) {
                return $statusInput;
            }
        }

        throw new \InvalidArgumentException('The release status is invalid.');
    }

    private static function parsePage(mixed $pageInput): int
    {
        if ($pageInput === null) {
            return 1;
        }

        if (!is_string($pageInput) || preg_match('/\A[1-9][0-9]{0,8}\z/', $pageInput) !== 1) {
            throw new \InvalidArgumentException('The page must be a canonical positive integer.');
        }

        $page = (int) $pageInput;
        if ($page > intdiv(PHP_INT_MAX, self::PAGE_SIZE) + 1) {
            throw new \InvalidArgumentException('The page exceeds the supported range.');
        }

        return $page;
    }

    private static function dateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value) && $value !== '') {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        }

        throw new \UnexpectedValueException('The release query returned an invalid date.');
    }
}

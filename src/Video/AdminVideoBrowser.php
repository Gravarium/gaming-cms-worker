<?php

declare(strict_types=1);

namespace App\\Video;

use App\\Entity\\Video;
use App\\Repository\\VideoRepository;
use Doctrine\\ORM\\QueryBuilder;
use Symfony\\Component\\HttpFoundation\\Request;

final class AdminVideoBrowser
{
    private const PAGE_SIZE = 25;

    private const STATUSES = ['all', 'published', 'scheduled', 'draft', 'disabled'];

    public function __construct(private readonly VideoRepository $videos)
    {
    }

    /**
     * @return array{
     *     search: string,
     *     status: string,
     *     category: ?int,
     *     page: int,
     *     rows: list<Video>,
     *     total: int,
     *     pageCount: int,
     *     query: array<string, int|string>
     * }
     */
    public function browse(Request $request): array
    {
        $filters = $this->filters($request);
        $now = new \\DateTimeImmutable();

        $countQuery = $this->filteredQuery($filters, $now)->select('COUNT(video.id)');
        $total = (int) $countQuery->getQuery()->getSingleScalarResult();
        $pageCount = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($filters['page'], $pageCount);

        $rowsQuery = $this->filteredQuery($filters, $now)
            ->addSelect('category')
            ->orderBy('video.createdAt', 'DESC')
            ->addOrderBy('video.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE);

        /** @var list<Video> $rows */
        $rows = $rowsQuery->getQuery()->getResult();

        $filters['page'] = $page;

        return [
            ...$filters,
            'rows' => $rows,
            'total' => $total,
            'pageCount' => $pageCount,
            'query' => $this->toQuery($filters),
        ];
    }

    /**
     * @return array<string, int|string>
     */
    public function queryFor(Request $request): array
    {
        return $this->toQuery($this->filters($request));
    }

    /**
     * @param array{search: string, status: string, category: ?int, page: int} $filters
     */
    private function filteredQuery(array $filters, \\DateTimeImmutable $now): QueryBuilder
    {
        $query = $this->videos->createQueryBuilder('video')
            ->leftJoin('video.category', 'category');

        if ($filters['search'] !== '') {
            $needle = mb_strtolower(strtr($filters['search'], ['!' => '!!', '%' => '!%', '_' => '!_']), 'UTF-8');
            $query->andWhere("LOWER(video.title) LIKE :search ESCAPE '!'")
                ->setParameter('search', '%'.$needle.'%');
        }

        if ($filters['category'] !== null) {
            $query->andWhere('category.id = :categoryId')
                ->setParameter('categoryId', $filters['category']);
        }

        switch ($filters['status']) {
            case 'published':
                $query->andWhere('video.enabled = :enabled')
                    ->andWhere('video.publishedAt IS NOT NULL')
                    ->andWhere('video.publishedAt <= :now')
                    ->setParameter('enabled', true)
                    ->setParameter('now', $now);
                break;
            case 'scheduled':
                $query->andWhere('video.enabled = :enabled')
                    ->andWhere('video.publishedAt > :now')
                    ->setParameter('enabled', true)
                    ->setParameter('now', $now);
                break;
            case 'draft':
                $query->andWhere('video.enabled = :enabled')
                    ->andWhere('video.publishedAt IS NULL')
                    ->setParameter('enabled', true);
                break;
            case 'disabled':
                $query->andWhere('video.enabled = :enabled')
                    ->setParameter('enabled', false);
                break;
        }

        return $query;
    }

    /**
     * @return array{search: string, status: string, category: ?int, page: int}
     */
    private function filters(Request $request): array
    {
        $query = $request->query->all();
        $search = trim($this->stringParameter($query, 'search', ''));
        if (!mb_check_encoding($search, 'UTF-8')
            || preg_match('/[\\x00-\\x1F\\x7F]/', $search) !== 0
            || mb_strlen($search, 'UTF-8') > 100
        ) {
            throw new \\InvalidArgumentException('Invalid video title search.');
        }

        $status = $this->stringParameter($query, 'status', 'all');
        if (!in_array($status, self::STATUSES, true)) {
            throw new \\InvalidArgumentException('Invalid video status filter.');
        }

        $categoryValue = $this->stringParameter($query, 'category', '');
        $category = null;
        if ($categoryValue !== '') {
            $validatedCategory = filter_var($categoryValue, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 2147483647],
            ]);
            if ($validatedCategory === false || (string) $validatedCategory !== $categoryValue) {
                throw new \\InvalidArgumentException('Invalid video category filter.');
            }
            $category = $validatedCategory;
        }

        $pageValue = $this->stringParameter($query, 'page', '1');
        $validatedPage = filter_var($pageValue, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 999999],
        ]);
        if ($validatedPage === false || (string) $validatedPage !== $pageValue) {
            throw new \\InvalidArgumentException('Invalid video page.');
        }

        return [
            'search' => $search,
            'status' => $status,
            'category' => $category,
            'page' => $validatedPage,
        ];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function stringParameter(array $query, string $key, string $default): string
    {
        if (!array_key_exists($key, $query)) {
            return $default;
        }

        if (!is_string($query[$key])) {
            throw new \\InvalidArgumentException('Invalid video filter type.');
        }

        return $query[$key];
    }

    /**
     * @param array{search: string, status: string, category: ?int, page: int} $filters
     * @return array<string, int|string>
     */
    private function toQuery(array $filters): array
    {
        $query = [];
        if ($filters['search'] !== '') {
            $query['search'] = $filters['search'];
        }
        if ($filters['status'] !== 'all') {
            $query['status'] = $filters['status'];
        }
        if ($filters['category'] !== null) {
            $query['category'] = $filters['category'];
        }
        if ($filters['page'] > 1) {
            $query['page'] = $filters['page'];
        }

        return $query;
    }
}

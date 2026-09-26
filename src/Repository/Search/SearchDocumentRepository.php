<?php

declare(strict_types=1);

namespace App\Repository\Search;

use App\Entity\Search\SearchDocument;
use App\Search\SearchFilters;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SearchDocument> */
final class SearchDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SearchDocument::class);
    }

    public function findOneBySource(string $sourceType, int $sourceId): ?SearchDocument
    {
        return $this->findOneBy(['sourceType' => $sourceType, 'sourceId' => $sourceId]);
    }

    /** @param list<string> $sourceTypes */
    public function findForIndex(array $sourceTypes): array
    {
        if ($sourceTypes === []) {
            return [];
        }

        /** @var list<SearchDocument> $documents */
        $documents = $this->createQueryBuilder('document')
            ->andWhere('document.sourceType IN (:sourceTypes)')
            ->setParameter('sourceTypes', $sourceTypes)
            ->getQuery()
            ->getResult();

        return $documents;
    }

    /**
     * @param list<string> $terms
     * @return list<SearchDocument>
     */
    public function findMatching(array $terms, SearchFilters $filters, int $limit = 500): array
    {
        $builder = $this->filteredBuilder($filters);
        foreach ($terms as $index => $term) {
            $parameter = 'term_'.$index;
            $builder
                ->andWhere('(LOWER(document.title) LIKE :'.$parameter.' OR LOWER(document.body) LIKE :'.$parameter.' OR LOWER(document.excerpt) LIKE :'.$parameter.')')
                ->setParameter($parameter, '%'.$term.'%');
        }

        /** @var list<SearchDocument> $documents */
        $documents = $builder
            ->orderBy('document.sourceUpdatedAt', 'DESC')
            ->addOrderBy('document.id', 'ASC')
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $documents;
    }

    /** @return list<SearchDocument> */
    public function findDiscoverable(SearchFilters $filters, int $limit = 500): array
    {
        /** @var list<SearchDocument> $documents */
        $documents = $this->filteredBuilder($filters)
            ->andWhere('document.recommendationOptOut = false')
            ->orderBy('document.popularity', 'DESC')
            ->addOrderBy('document.sourceUpdatedAt', 'DESC')
            ->addOrderBy('document.id', 'ASC')
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();

        return $documents;
    }

    private function filteredBuilder(SearchFilters $filters): QueryBuilder
    {
        $builder = $this->createQueryBuilder('document');
        if ($filters->moduleKey !== null) {
            $builder->andWhere('document.moduleKey = :module')->setParameter('module', $filters->moduleKey);
        }
        if ($filters->documentType !== null) {
            $builder->andWhere('document.documentType = :documentType')->setParameter('documentType', $filters->documentType);
        }

        return $builder;
    }
}

<?php

declare(strict_types=1);

namespace App\Repository\Hardware;

use App\Entity\Hardware\HardwareProduct;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HardwareProduct> */
final class HardwareProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, HardwareProduct::class); }

    /** @return array{products: list<HardwareProduct>, total: int} */
    public function publicDirectory(string $query, string $category, int $page, int $perPage = 24): array
    {
        $count = (int) $this->publicFilter($query, $category)->select('COUNT(product.id)')->getQuery()->getSingleScalarResult();
        $products = $this->publicFilter($query, $category)
            ->orderBy('product.category', 'ASC')->addOrderBy('product.name', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery()->getResult();

        return ['products' => $products, 'total' => $count];
    }

    /** @return list<string> */
    public function publicCategories(): array
    {
        $rows = $this->createQueryBuilder('product')->select('DISTINCT product.category AS category')
            ->andWhere('product.published = true')->orderBy('product.category', 'ASC')->getQuery()->getScalarResult();

        return array_values(array_map(static fn (array $row): string => (string) $row['category'], $rows));
    }

    public function publicById(int $id): ?HardwareProduct
    {
        return $this->createQueryBuilder('product')->leftJoin('product.specifications', 'specification')->addSelect('specification')
            ->andWhere('product.id = :id')->andWhere('product.published = true')->setParameter('id', $id)
            ->getQuery()->getOneOrNullResult();
    }

    /** @param list<int> $ids
     *  @return list<HardwareProduct>
     */
    public function publicByIds(array $ids): array
    {
        if ($ids === []) { return []; }

        return $this->createQueryBuilder('product')->leftJoin('product.specifications', 'specification')->addSelect('specification')
            ->andWhere('product.id IN (:ids)')->andWhere('product.published = true')->setParameter('ids', $ids)
            ->orderBy('product.name', 'ASC')->getQuery()->getResult();
    }

    /** @return list<HardwareProduct> */
    public function adminDirectory(): array
    {
        return $this->createQueryBuilder('product')->orderBy('product.createdAt', 'DESC')->addOrderBy('product.id', 'DESC')
            ->setMaxResults(300)->getQuery()->getResult();
    }

    /** @return list<HardwareProduct> */
    public function publishedChoices(): array
    {
        return $this->createQueryBuilder('product')->andWhere('product.published = true')->orderBy('product.name', 'ASC')->getQuery()->getResult();
    }

    private function publicFilter(string $query, string $category): QueryBuilder
    {
        $builder = $this->createQueryBuilder('product')->andWhere('product.published = true');
        if ($query !== '') {
            $builder->andWhere('(LOWER(product.name) LIKE :query OR LOWER(product.manufacturer) LIKE :query)')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }
        if ($category !== '') { $builder->andWhere('product.category = :category')->setParameter('category', $category); }

        return $builder;
    }
}

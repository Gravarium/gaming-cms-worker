<?php
declare(strict_types=1);
namespace App\Repository;
use App\Entity\ContentEntry;
use App\Entity\ContentRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
/** @extends ServiceEntityRepository<ContentRevision> */
final class ContentRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, ContentRevision::class); }
    public function nextNumber(ContentEntry $entry): int
    {
        $max = $this->createQueryBuilder('revision')->select('MAX(revision.revisionNumber)')
            ->andWhere('revision.entry = :entry')->setParameter('entry', $entry)
            ->getQuery()->getSingleScalarResult();
        return (int) $max + 1;
    }
    /** @return list<ContentRevision> */
    public function forEntry(ContentEntry $entry): array
    {
        return $this->findBy(['entry' => $entry], ['revisionNumber' => 'DESC'], 50);
    }
}

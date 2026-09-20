<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MediaFolder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MediaFolder> */
final class MediaFolderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaFolder::class);
    }

    /** @return list<MediaFolder> */
    public function ordered(): array
    {
        return $this->createQueryBuilder('folder')
            ->leftJoin('folder.parent', 'parent')->addSelect('parent')
            ->orderBy('parent.name', 'ASC')->addOrderBy('folder.name', 'ASC')
            ->getQuery()->getResult();
    }
}

<?php

declare(strict_types=1);
namespace App\Repository;
use App\Entity\ContentRedirect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
/** @extends ServiceEntityRepository<ContentRedirect> */
final class ContentRedirectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, ContentRedirect::class); }
    public function findTarget(string $type, string $slug): ?ContentRedirect { return $this->findOneBy(['type' => $type, 'sourceSlug' => $slug]); }
}

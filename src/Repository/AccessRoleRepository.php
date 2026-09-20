<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccessRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AccessRole> */
final class AccessRoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, AccessRole::class); }
    /** @return list<AccessRole> */
    public function assignable(): array { return $this->findBy(['active' => true], ['name' => 'ASC']); }
}

<?php

declare(strict_types=1);

namespace App\Repository\Profile;

use App\Entity\Profile\MemberProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MemberProfile> */
final class MemberProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemberProfile::class);
    }

    public function forUser(User $user): ?MemberProfile
    {
        return $this->find($user->getId());
    }
}

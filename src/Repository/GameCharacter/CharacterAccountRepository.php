<?php

declare(strict_types=1);

namespace App\Repository\GameCharacter;

use App\Entity\GameCharacter\CharacterAccount;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CharacterAccount> */
final class CharacterAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CharacterAccount::class);
    }

    /** @return list<CharacterAccount> */
    public function activeForOwner(User $owner): array
    {
        return $this->createQueryBuilder('account')
            ->andWhere('account.owner = :owner')
            ->andWhere('account.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->orderBy('account.displayName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

<?php

declare(strict_types=1);

namespace App\Repository\GameCharacter;

use App\Entity\GameCharacter\CharacterAccount;
use App\Entity\GameCharacter\CharacterProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CharacterProfile> */
final class CharacterProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CharacterProfile::class);
    }

    /** @return list<CharacterProfile> */
    public function publicProfiles(): array
    {
        return $this->createQueryBuilder('profile')
            ->join('profile.account', 'account')
            ->join('account.game', 'game')
            ->andWhere('profile.deletedAt IS NULL')
            ->andWhere('account.deletedAt IS NULL')
            ->andWhere('profile.visibilityDefault = true')
            ->andWhere('game.enabled = true')
            ->orderBy('game.name', 'ASC')
            ->addOrderBy('profile.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<CharacterProfile> */
    public function activeForOwner(User $owner): array
    {
        return $this->createQueryBuilder('profile')
            ->join('profile.account', 'account')
            ->andWhere('account.owner = :owner')
            ->andWhere('profile.deletedAt IS NULL')
            ->andWhere('account.deletedAt IS NULL')
            ->setParameter('owner', $owner)
            ->orderBy('profile.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveForOwner(User $owner, int $id): ?CharacterProfile
    {
        return $this->createQueryBuilder('profile')
            ->join('profile.account', 'account')
            ->andWhere('profile.id = :id')
            ->andWhere('account.owner = :owner')
            ->andWhere('profile.deletedAt IS NULL')
            ->andWhere('account.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<CharacterProfile> */
    public function activeForAccount(CharacterAccount $account): array
    {
        return $this->createQueryBuilder('profile')
            ->andWhere('profile.account = :account')
            ->andWhere('profile.deletedAt IS NULL')
            ->setParameter('account', $account)
            ->orderBy('profile.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

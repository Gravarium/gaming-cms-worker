<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AccountToken> */
final class AccountTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountToken::class);
    }

    public function usable(string $tokenHash, string $purpose): ?AccountToken
    {
        $token = $this->findOneBy(['tokenHash' => $tokenHash, 'purpose' => $purpose]);
        return $token?->isUsable() ? $token : null;
    }

    public function revokeActive(User $user, string $purpose): void
    {
        $this->createQueryBuilder('token')
            ->update()
            ->set('token.usedAt', ':now')
            ->andWhere('token.user = :user')
            ->andWhere('token.purpose = :purpose')
            ->andWhere('token.usedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->setParameter('purpose', $purpose)
            ->getQuery()->execute();
    }
}

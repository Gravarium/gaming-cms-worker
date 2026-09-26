<?php

declare(strict_types=1);

namespace App\Repository\Invitation;

use App\Entity\Invitation\MemberInvitation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MemberInvitation> */
final class MemberInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MemberInvitation::class);
    }

    public function byRawToken(string $rawToken): ?MemberInvitation
    {
        if (strlen($rawToken) < 20 || strlen($rawToken) > 200) {
            return null;
        }

        return $this->findOneBy(['tokenHash' => hash('sha256', $rawToken)]);
    }

    public function countPendingByCreatorSince(User $creator, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('invitation')
            ->select('COUNT(invitation.id)')
            ->andWhere('invitation.createdBy = :creator')
            ->andWhere('invitation.status = :pending')
            ->andWhere('invitation.createdAt >= :since')
            ->setParameter('creator', $creator)
            ->setParameter('pending', MemberInvitation::STATUS_PENDING)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countActiveForEmail(string $email, \DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('invitation')
            ->select('COUNT(invitation.id)')
            ->andWhere('invitation.email = :email')
            ->andWhere('invitation.status = :pending')
            ->andWhere('invitation.expiresAt > :now')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->setParameter('pending', MemberInvitation::STATUS_PENDING)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

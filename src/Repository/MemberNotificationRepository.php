<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MemberNotification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MemberNotification> */
final class MemberNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, MemberNotification::class); }
    /** @return list<MemberNotification> */
    public function latestForUser(User $user): array { return $this->findBy(['user' => $user], ['createdAt' => 'DESC'], 50); }
    public function unreadCount(User $user): int { return $this->count(['user' => $user, 'readAt' => null]); }
}

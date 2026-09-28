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
    public function latestForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC', 'id' => 'DESC'], 50);
    }

    /**
     * @return list<MemberNotification>
     */
    public function pageForUser(User $user, int $limit, int $offset): array
    {
        if ($limit < 1 || $limit > 50 || $offset < 0) {
            throw new \InvalidArgumentException('Notification page bounds are invalid.');
        }

        return $this->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
            $limit,
            $offset,
        );
    }

    /** @return list<MemberNotification> */
    public function unreadForUser(User $user): array
    {
        return $this->findBy(
            ['user' => $user, 'readAt' => null],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
        );
    }

    public function countForUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    public function unreadCount(User $user): int
    {
        return $this->count(['user' => $user, 'readAt' => null]);
    }
}

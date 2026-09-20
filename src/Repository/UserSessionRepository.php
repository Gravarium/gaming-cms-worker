<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<UserSession> */
final class UserSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, UserSession::class); }
    public function findBySessionId(string $sessionId): ?UserSession { return $this->findOneBy(['sessionHash' => hash('sha256', $sessionId)]); }
    /** @return list<UserSession> */
    public function activeFor(User $user): array { return $this->findBy(['user' => $user, 'revokedAt' => null], ['lastSeenAt' => 'DESC']); }
    public function revokeAll(User $user, ?string $exceptHash = null): int
    {
        $count = 0;
        foreach ($this->activeFor($user) as $session) {
            if ($exceptHash !== null && hash_equals($exceptHash, $session->getSessionHash())) { continue; }
            $session->revoke(); ++$count;
        }
        return $count;
    }
}

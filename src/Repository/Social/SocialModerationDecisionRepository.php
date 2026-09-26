<?php

declare(strict_types=1);

namespace App\Repository\Social;

use App\Entity\Social\SocialModerationDecision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SocialModerationDecision> */
final class SocialModerationDecisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialModerationDecision::class);
    }

    /** @return list<SocialModerationDecision> */
    public function forTarget(string $kind, int $id, int $limit = 100): array
    {
        return $this->findBy(
            ['targetKind' => $kind, 'targetId' => $id],
            ['createdAt' => 'DESC'],
            max(1, min(200, $limit)),
        );
    }
}

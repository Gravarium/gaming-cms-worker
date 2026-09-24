<?php

declare(strict_types=1);

namespace App\Repository\VideoDiscovery;

use App\Entity\User;
use App\Entity\VideoDiscovery\VideoDiscoveryProfile;
use App\Entity\VideoDiscovery\VideoTag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<VideoDiscoveryProfile> */
final class VideoDiscoveryProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoDiscoveryProfile::class);
    }

    /** @return list<VideoDiscoveryProfile> */
    public function searchPublished(
        string $query = '',
        ?VideoTag $tag = null,
        int $limit = 50,
        ?User $viewer = null,
    ): array {
        $query = mb_substr(trim($query), 0, 160);
        $limit = max(1, min(100, $limit));

        $qb = $this->createQueryBuilder('profile')
            ->join('profile.video', 'video')
            ->leftJoin('profile.creator', 'creator')
            ->addSelect('video', 'creator')
            ->andWhere('profile.discoverable = true')
            ->andWhere('video.enabled = true')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('video.publishedAt', 'DESC')
            ->setMaxResults($limit);

        if ($viewer instanceof User && $viewer->isActive() && !$viewer->isLocked()) {
            $qb->andWhere(
                'profile.visibility = :publicVisibility
                OR profile.visibility = :memberVisibility
                OR (profile.visibility = :privateVisibility AND creator.owner = :viewer)'
            )
                ->setParameter('publicVisibility', VideoDiscoveryProfile::VISIBILITY_PUBLIC)
                ->setParameter('memberVisibility', VideoDiscoveryProfile::VISIBILITY_MEMBER)
                ->setParameter('privateVisibility', VideoDiscoveryProfile::VISIBILITY_PRIVATE)
                ->setParameter('viewer', $viewer);
        } else {
            $qb->andWhere('profile.visibility = :publicVisibility')
                ->setParameter('publicVisibility', VideoDiscoveryProfile::VISIBILITY_PUBLIC);
        }

        if ($query !== '') {
            $qb->andWhere('LOWER(video.title) LIKE :query OR LOWER(video.description) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }

        if ($tag !== null) {
            $qb->join('profile.tags', 'tag')
                ->andWhere('tag = :tag')
                ->setParameter('tag', $tag);
        }

        return $qb->getQuery()->getResult();
    }
}

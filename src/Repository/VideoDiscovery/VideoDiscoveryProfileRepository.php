<?php

declare(strict_types=1);

namespace App\Repository\VideoDiscovery;

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
    public function searchPublished(string $query = '', ?VideoTag $tag = null, int $limit = 50): array
    {
        $query = mb_substr(trim($query), 0, 160);
        $limit = max(1, min(100, $limit));

        $qb = $this->createQueryBuilder('profile')
            ->join('profile.video', 'video')
            ->andWhere('profile.discoverable = true')
            ->andWhere('video.enabled = true')
            ->andWhere('video.publishedAt IS NOT NULL')
            ->andWhere('video.publishedAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('video.publishedAt', 'DESC')
            ->setMaxResults($limit);

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

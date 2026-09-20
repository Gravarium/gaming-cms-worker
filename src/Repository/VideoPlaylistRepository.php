<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\VideoPlaylist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<VideoPlaylist> */
final class VideoPlaylistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, VideoPlaylist::class); }

    /** @return list<VideoPlaylist> */
    public function findEnabled(): array
    {
        return $this->findBy(['enabled' => true], ['title' => 'ASC']);
    }

    public function findEnabledBySlug(string $slug): ?VideoPlaylist
    {
        return $this->findOneBy(['slug' => $slug, 'enabled' => true]);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $builder = $this->createQueryBuilder('playlist')->select('COUNT(playlist.id)')
            ->andWhere('playlist.slug = :slug')->setParameter('slug', $slug);
        if ($exceptId !== null) { $builder->andWhere('playlist.id != :id')->setParameter('id', $exceptId); }
        return (int) $builder->getQuery()->getSingleScalarResult() > 0;
    }
}

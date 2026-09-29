<?php

declare(strict_types=1);

namespace App\Repository\Hardware;

use App\Entity\Hardware\HardwareCommunitySetup;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HardwareCommunitySetup> */
final class HardwareCommunitySetupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, HardwareCommunitySetup::class); }

    /** @return list<HardwareCommunitySetup> */
    public function pending(): array
    {
        return $this->createQueryBuilder('setup')->leftJoin('setup.owner', 'owner')->addSelect('owner')
            ->andWhere('setup.moderated = false')->orderBy('setup.createdAt', 'ASC')->setMaxResults(100)->getQuery()->getResult();
    }

    /** @return list<HardwareCommunitySetup> */
    public function moderationQueue(): array
    {
        return $this->createQueryBuilder('setup')->leftJoin('setup.owner', 'owner')->addSelect('owner')
            ->orderBy('setup.moderated', 'ASC')->addOrderBy('setup.createdAt', 'ASC')->setMaxResults(200)->getQuery()->getResult();
    }

    /** @return list<HardwareCommunitySetup> */
    public function publicSetups(): array
    {
        return $this->createQueryBuilder('setup')->leftJoin('setup.owner', 'owner')->addSelect('owner')
            ->andWhere('setup.moderated = true')->orderBy('setup.createdAt', 'DESC')->setMaxResults(100)->getQuery()->getResult();
    }

    /** @return list<HardwareCommunitySetup> */
    public function forOwner(User $owner): array
    {
        return $this->createQueryBuilder('setup')->andWhere('setup.owner = :owner')->setParameter('owner', $owner)
            ->orderBy('setup.createdAt', 'DESC')->setMaxResults(100)->getQuery()->getResult();
    }

    /** @param list<int> $productIds
     *  @return list<HardwareCommunitySetup>
     */
    public function publicContaining(array $productIds): array
    {
        $wanted = array_fill_keys($productIds, true);
        return array_values(array_filter($this->publicSetups(), static function (HardwareCommunitySetup $setup) use ($wanted): bool {
            foreach ($setup->getProductIds() as $id) { if (isset($wanted[$id])) { return true; } }
            return false;
        }));
    }
}

<?php

declare(strict_types=1);

namespace App\Repository\Newsletter;

use App\Entity\Newsletter\NewsletterCampaign;
use App\Entity\Newsletter\NewsletterDelivery;
use App\Entity\Newsletter\NewsletterSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<NewsletterDelivery> */
final class NewsletterDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsletterDelivery::class);
    }

    public function existsFor(NewsletterCampaign $campaign, NewsletterSubscription $subscription): bool
    {
        return $this->count(['campaign' => $campaign, 'subscription' => $subscription]) > 0;
    }

    /** @return list<NewsletterDelivery> */
    public function dispatchable(NewsletterCampaign $campaign, \DateTimeImmutable $now, int $limit): array
    {
        return $this->createQueryBuilder('delivery')
            ->andWhere('delivery.campaign = :campaign')
            ->andWhere('(delivery.status = :pending OR (delivery.status = :retry AND delivery.retryAt <= :now))')
            ->setParameter('campaign', $campaign)
            ->setParameter('pending', NewsletterDelivery::STATUS_PENDING)
            ->setParameter('retry', NewsletterDelivery::STATUS_RETRY)
            ->setParameter('now', $now)
            ->orderBy('delivery.id', 'ASC')
            ->setMaxResults(max(1, min(10000, $limit)))
            ->getQuery()
            ->getResult();
    }

    public function countOutstanding(NewsletterCampaign $campaign): int
    {
        return (int) $this->createQueryBuilder('delivery')
            ->select('COUNT(delivery.id)')
            ->andWhere('delivery.campaign = :campaign')
            ->andWhere('delivery.status IN (:statuses)')
            ->setParameter('campaign', $campaign)
            ->setParameter('statuses', [NewsletterDelivery::STATUS_PENDING, NewsletterDelivery::STATUS_RETRY])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countFailed(NewsletterCampaign $campaign): int
    {
        return $this->count(['campaign' => $campaign, 'status' => NewsletterDelivery::STATUS_FAILED]);
    }
}

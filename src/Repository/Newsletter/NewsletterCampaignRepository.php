<?php

declare(strict_types=1);

namespace App\Repository\Newsletter;

use App\Entity\Newsletter\NewsletterCampaign;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<NewsletterCampaign> */
final class NewsletterCampaignRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsletterCampaign::class);
    }

    /** @return list<NewsletterCampaign> */
    public function due(\DateTimeImmutable $now, int $limit = 20): array
    {
        return $this->createQueryBuilder('campaign')
            ->andWhere('campaign.status = :scheduled')
            ->andWhere('campaign.scheduledAt <= :now')
            ->setParameter('scheduled', NewsletterCampaign::STATUS_SCHEDULED)
            ->setParameter('now', $now)
            ->orderBy('campaign.scheduledAt', 'ASC')
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();
    }
}

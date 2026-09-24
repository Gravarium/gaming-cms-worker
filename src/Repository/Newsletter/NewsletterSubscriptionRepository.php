<?php

declare(strict_types=1);

namespace App\Repository\Newsletter;

use App\Entity\Newsletter\NewsletterSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<NewsletterSubscription> */
final class NewsletterSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsletterSubscription::class);
    }

    public function findByEmail(string $email): ?NewsletterSubscription
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    /** @return list<NewsletterSubscription> */
    public function activeForSegment(string $segment, int $limit): array
    {
        $limit = max(1, min(10000, $limit));
        $qb = $this->createQueryBuilder('subscription')
            ->andWhere('subscription.status = :active')
            ->andWhere('subscription.confirmedAt IS NOT NULL')
            ->andWhere('subscription.suppressedAt IS NULL')
            ->setParameter('active', NewsletterSubscription::STATUS_ACTIVE)
            ->orderBy('subscription.id', 'ASC')
            ->setMaxResults($limit);

        if ($segment === 'members') {
            $qb->andWhere('subscription.user IS NOT NULL');
        } elseif ($segment === 'guests') {
            $qb->andWhere('subscription.user IS NULL');
        } elseif ($segment !== 'all') {
            throw new \InvalidArgumentException('Unknown newsletter segment.');
        }

        return $qb->getQuery()->getResult();
    }
}

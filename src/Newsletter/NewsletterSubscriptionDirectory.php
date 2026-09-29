<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Entity\Newsletter\NewsletterSubscription;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class NewsletterSubscriptionDirectory
{
    public const PAGE_SIZE = 50;

    /** @var list<string> */
    public const STATUSES = [
        NewsletterSubscription::STATUS_PENDING,
        NewsletterSubscription::STATUS_ACTIVE,
        NewsletterSubscription::STATUS_UNSUBSCRIBED,
        NewsletterSubscription::STATUS_SUPPRESSED,
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{
     *     subscriptions: list<NewsletterSubscription>,
     *     email: string,
     *     status: string,
     *     page: int,
     *     pages: int,
     *     total: int
     * }
     */
    public function search(string $email, string $status, int $page): array
    {
        $email = trim($email);
        if (mb_strlen($email) > 180) {
            throw new \InvalidArgumentException('Die E-Mail-Suche darf höchstens 180 Zeichen lang sein.');
        }

        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unbekannter Abonnentenstatus.');
        }

        $countQuery = $this->entityManager->createQueryBuilder()
            ->select('COUNT(subscription.id)')
            ->from(NewsletterSubscription::class, 'subscription');
        $this->applyFilters($countQuery, $email, $status);
        $total = (int) $countQuery->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $currentPage = min(max(1, $page), $pages);

        $query = $this->entityManager->createQueryBuilder()
            ->select('subscription')
            ->from(NewsletterSubscription::class, 'subscription')
            ->orderBy('subscription.createdAt', 'DESC')
            ->addOrderBy('subscription.id', 'DESC')
            ->setFirstResult(($currentPage - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE);
        $this->applyFilters($query, $email, $status);

        /** @var list<NewsletterSubscription> $subscriptions */
        $subscriptions = $query->getQuery()->getResult();

        return [
            'subscriptions' => $subscriptions,
            'email' => $email,
            'status' => $status,
            'page' => $currentPage,
            'pages' => $pages,
            'total' => $total,
        ];
    }

    private function applyFilters(QueryBuilder $query, string $email, string $status): void
    {
        if ($email !== '') {
            $escaped = strtr(mb_strtolower($email), ['!' => '!!', '%' => '!%', '_' => '!_']);
            $query
                ->andWhere("LOWER(subscription.email) LIKE :email ESCAPE '!'")
                ->setParameter('email', '%'.$escaped.'%');
        }

        if ($status !== '') {
            $query
                ->andWhere('subscription.status = :status')
                ->setParameter('status', $status);
        }
    }
}

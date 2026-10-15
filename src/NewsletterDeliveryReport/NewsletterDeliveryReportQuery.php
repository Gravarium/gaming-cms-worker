<?php

declare(strict_types=1);

namespace App\NewsletterDeliveryReport;

use App\Entity\Newsletter\NewsletterCampaign;
use App\Entity\Newsletter\NewsletterDelivery;
use App\Repository\Newsletter\NewsletterCampaignRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class NewsletterDeliveryReportQuery
{
    private const PAGE_SIZE = 25;

    public function __construct(
        private NewsletterCampaignRepository $campaigns,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{campaigns: list<array{
     *     title: string,
     *     status: string,
     *     sentAt: \DateTimeImmutable|null,
     *     counts: array{sent: int, pending: int, retry: int, failed: int, suppressed: int}
     * }>, nextCursor: string|null}
     */
    public function recentCampaigns(?string $cursor = null): array
    {
        $after = $this->decodeCursor($cursor);
        $query = $this->campaigns->createQueryBuilder('campaign')
            ->orderBy('campaign.createdAt', 'DESC')
            ->addOrderBy('campaign.id', 'DESC')
            ->setMaxResults(self::PAGE_SIZE + 1);
        if ($after !== null) {
            $query
                ->andWhere('(campaign.createdAt < :cursorCreatedAt OR (campaign.createdAt = :cursorCreatedAt AND campaign.id < :cursorId))')
                ->setParameter('cursorCreatedAt', $after['createdAt'])
                ->setParameter('cursorId', $after['id']);
        }

        /** @var list<NewsletterCampaign> $campaigns */
        $campaigns = $query->getQuery()->getResult();
        $hasMore = count($campaigns) > self::PAGE_SIZE;
        if ($hasMore) {
            array_pop($campaigns);
        }

        $campaignIds = [];
        foreach ($campaigns as $campaign) {
            $id = $campaign->getId();
            if ($id !== null) {
                $campaignIds[] = $id;
            }
        }

        /** @var array<int, array{sent: int, pending: int, retry: int, failed: int, suppressed: int}> $countsByCampaign */
        $countsByCampaign = [];
        foreach ($campaignIds as $id) {
            $countsByCampaign[$id] = [
                'sent' => 0,
                'pending' => 0,
                'retry' => 0,
                'failed' => 0,
                'suppressed' => 0,
            ];
        }

        if ($campaignIds !== []) {
            $rows = $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(delivery.campaign) AS campaignId')
                ->addSelect('delivery.status AS status')
                ->addSelect('COUNT(delivery.id) AS deliveryCount')
                ->from(NewsletterDelivery::class, 'delivery')
                ->where('IDENTITY(delivery.campaign) IN (:campaignIds)')
                ->setParameter('campaignIds', $campaignIds)
                ->groupBy('campaignId')
                ->addGroupBy('status')
                ->getQuery()
                ->getArrayResult();

            foreach ($rows as $row) {
                $campaignId = (int) $row['campaignId'];
                if (!isset($countsByCampaign[$campaignId])) {
                    continue;
                }

                $count = (int) $row['deliveryCount'];
                switch ((string) $row['status']) {
                    case NewsletterDelivery::STATUS_SENT:
                        $countsByCampaign[$campaignId]['sent'] = $count;
                        break;
                    case NewsletterDelivery::STATUS_PENDING:
                        $countsByCampaign[$campaignId]['pending'] = $count;
                        break;
                    case NewsletterDelivery::STATUS_RETRY:
                        $countsByCampaign[$campaignId]['retry'] = $count;
                        break;
                    case NewsletterDelivery::STATUS_FAILED:
                        $countsByCampaign[$campaignId]['failed'] = $count;
                        break;
                    case NewsletterDelivery::STATUS_SUPPRESSED:
                        $countsByCampaign[$campaignId]['suppressed'] = $count;
                        break;
                }
            }
        }

        $reportCampaigns = [];
        foreach ($campaigns as $campaign) {
            $id = $campaign->getId();
            if ($id === null) {
                continue;
            }

            $reportCampaigns[] = [
                'title' => $campaign->getTitle(),
                'status' => $campaign->getStatus(),
                'sentAt' => $campaign->getSentAt(),
                'counts' => $countsByCampaign[$id],
            ];
        }

        $lastCampaign = $campaigns === [] ? null : $campaigns[array_key_last($campaigns)];

        return [
            'campaigns' => $reportCampaigns,
            'nextCursor' => $hasMore && $lastCampaign !== null
                ? $this->encodeCursor($lastCampaign)
                : null,
        ];
    }

    private function encodeCursor(NewsletterCampaign $campaign): string
    {
        $id = $campaign->getId();
        if ($id === null) {
            throw new \LogicException('A persisted newsletter campaign requires an ID.');
        }

        $payload = $campaign->getCreatedAt()->format('Y-m-d\TH:i:s.u').'|'.$id;

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /** @return array{createdAt: \DateTimeImmutable, id: int}|null */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }

        if ($cursor === '' || strlen($cursor) > 80 || preg_match('/\A[A-Za-z0-9_-]+\z/', $cursor) !== 1) {
            throw new \InvalidArgumentException('Invalid newsletter report cursor.');
        }

        $base64 = strtr($cursor, '-_', '+/');
        $decoded = base64_decode($base64.str_repeat('=', (4 - strlen($base64) % 4) % 4), true);
        if (!is_string($decoded) || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $cursor) {
            throw new \InvalidArgumentException('Invalid newsletter report cursor.');
        }

        if (preg_match('/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6})\|([1-9]\d{0,18})\z/', $decoded, $matches) !== 1) {
            throw new \InvalidArgumentException('Invalid newsletter report cursor.');
        }

        $createdAt = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u', $matches[1]);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if ($createdAt === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $createdAt->format('Y-m-d\TH:i:s.u') !== $matches[1]
        ) {
            throw new \InvalidArgumentException('Invalid newsletter report cursor.');
        }

        $id = filter_var($matches[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            throw new \InvalidArgumentException('Invalid newsletter report cursor.');
        }

        return ['createdAt' => $createdAt, 'id' => $id];
    }
}

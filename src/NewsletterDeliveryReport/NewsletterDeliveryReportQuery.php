<?php

declare(strict_types=1);

namespace App\NewsletterDeliveryReport;

use App\Entity\Newsletter\NewsletterDelivery;
use App\Repository\Newsletter\NewsletterCampaignRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class NewsletterDeliveryReportQuery
{
    private const CAMPAIGN_LIMIT = 100;

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
     * }>}
     */
    public function recentCampaigns(): array
    {
        $campaigns = $this->campaigns->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], self::CAMPAIGN_LIMIT);
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

        return ['campaigns' => $reportCampaigns];
    }
}

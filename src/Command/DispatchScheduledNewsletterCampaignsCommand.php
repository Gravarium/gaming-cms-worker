<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Newsletter\NewsletterCampaign;
use App\Entity\Newsletter\NewsletterDelivery;
use App\Module\CmsModuleManager;
use App\Newsletter\NewsletterDispatchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:newsletter:dispatch-due',
    description: 'Verarbeitet fällige Newsletter-Kampagnen in begrenzten Versandläufen.',
)]
final class DispatchScheduledNewsletterCampaignsCommand extends Command
{
    private const MAX_CAMPAIGNS = 100;
    private const MAX_DELIVERY_QUOTA = 1000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NewsletterDispatchService $dispatch,
        private readonly CmsModuleManager $modules,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'campaign-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximale Zahl fälliger Kampagnen pro Lauf (1–100).',
                '20',
            )
            ->addOption(
                'delivery-quota',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximale Zahl Zustellversuche je Kampagne pro Lauf (1–1000).',
                '100',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $campaignLimit = $this->positiveInteger($input->getOption('campaign-limit'), self::MAX_CAMPAIGNS);
        $deliveryQuota = $this->positiveInteger($input->getOption('delivery-quota'), self::MAX_DELIVERY_QUOTA);
        if ($campaignLimit === null || $deliveryQuota === null) {
            $output->writeln('<error>campaign-limit muss 1–100 und delivery-quota 1–1000 betragen.</error>');

            return Command::FAILURE;
        }

        if (!$this->modules->isEnabled('notifications')) {
            $output->writeln('<comment>Newsletter-Versand übersprungen: Das Benachrichtigungsmodul ist deaktiviert.</comment>');

            return Command::SUCCESS;
        }

        $now = new \DateTimeImmutable();
        $campaigns = $this->dueCampaigns($now, $campaignLimit);
        $sent = 0;
        $failedAttempts = 0;
        $suppressed = 0;
        $outstanding = 0;
        $runErrors = 0;

        foreach ($campaigns as $campaign) {
            try {
                $result = $this->dispatch->dispatch($campaign, $now, $deliveryQuota);
                $sent += $result->sent;
                $failedAttempts += $result->failedAttempts;
                $suppressed += $result->suppressed;
                $outstanding += $result->outstanding;
            } catch (\Throwable $exception) {
                ++$runErrors;
                $output->writeln(sprintf(
                    '<error>Newsletter-Kampagne %d wurde nicht verarbeitet (%s).</error>',
                    $campaign->getId() ?? 0,
                    $exception::class,
                ));
            }
        }

        $output->writeln(sprintf(
            '<info>%d Kampagnen verarbeitet: %d zugestellt, %d Fehlversuche, %d unterdrückt, %d offen, %d Laufzeitfehler.</info>',
            count($campaigns),
            $sent,
            $failedAttempts,
            $suppressed,
            $outstanding,
            $runErrors,
        ));

        return $runErrors === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return list<NewsletterCampaign> */
    private function dueCampaigns(\DateTimeImmutable $now, int $limit): array
    {
        $scheduledQuery = $this->entityManager->createQueryBuilder();
        $scheduledQuery
            ->select('campaign')
            ->from(NewsletterCampaign::class, 'campaign')
            ->andWhere('campaign.status = :scheduled')
            ->andWhere('campaign.scheduledAt IS NOT NULL')
            ->andWhere('campaign.scheduledAt <= :now')
            ->setParameter('scheduled', NewsletterCampaign::STATUS_SCHEDULED)
            ->setParameter('now', $now)
            ->orderBy('campaign.scheduledAt', 'ASC')
            ->addOrderBy('campaign.id', 'ASC')
            ->setMaxResults($limit);

        /** @var list<NewsletterCampaign> $scheduledCampaigns */
        $scheduledCampaigns = $scheduledQuery->getQuery()->getResult();

        $readyDelivery = $this->entityManager->createQueryBuilder()
            ->select('delivery.id')
            ->from(NewsletterDelivery::class, 'delivery')
            ->andWhere('delivery.campaign = campaign')
            ->andWhere(
                '(delivery.status = :pending OR (delivery.status = :retry AND delivery.retryAt <= :now))',
            );

        $resumableQuery = $this->entityManager->createQueryBuilder();
        $resumableQuery
            ->select('campaign')
            ->from(NewsletterCampaign::class, 'campaign')
            ->andWhere('campaign.status = :sending')
            ->andWhere($resumableQuery->expr()->exists($readyDelivery->getDQL()))
            ->setParameter('sending', NewsletterCampaign::STATUS_SENDING)
            ->setParameter('pending', NewsletterDelivery::STATUS_PENDING)
            ->setParameter('retry', NewsletterDelivery::STATUS_RETRY)
            ->setParameter('now', $now)
            ->orderBy('campaign.updatedAt', 'ASC')
            ->addOrderBy('campaign.id', 'ASC')
            ->setMaxResults($limit);

        /** @var list<NewsletterCampaign> $resumableCampaigns */
        $resumableCampaigns = $resumableQuery->getQuery()->getResult();

        $candidates = [...$scheduledCampaigns, ...$resumableCampaigns];
        usort($candidates, static function (NewsletterCampaign $left, NewsletterCampaign $right): int {
            $dateOrder = ($left->getScheduledAt() ?? $left->getUpdatedAt()) <=> ($right->getScheduledAt() ?? $right->getUpdatedAt());
            if ($dateOrder !== 0) {
                return $dateOrder;
            }

            return ($left->getId() ?? 0) <=> ($right->getId() ?? 0);
        });

        return array_slice($candidates, 0, $limit);
    }

    private function positiveInteger(mixed $value, int $maximum): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($parsed) || $parsed < 1 || $parsed > $maximum) {
            return null;
        }

        return $parsed;
    }
}

<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorHealthStatus;
use App\Repository\ExternalConnectorHealthStatusRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ExternalConnectorHealthRecorder
{
    public function __construct(
        private ExternalConnectorHealthStatusRepository $statuses,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function record(ExternalConnectorExecutionSummary $summary): void
    {
        if ($summary->results === []) {
            return;
        }

        $checkedAt = new \DateTimeImmutable();
        foreach ($summary->results as $result) {
            $status = $this->statuses->findOneBy([
                'capability' => $summary->capability,
                'targetKey' => $result->targetKey,
            ]) ?? (new ExternalConnectorHealthStatus())
                ->setCapability($summary->capability)
                ->setTargetKey($result->targetKey);

            $status
                ->setProviderKey($result->providerKey)
                ->setSuccessful($result->successful)
                ->setCheckedAt($checkedAt);
            $this->entityManager->persist($status);
        }

        $this->entityManager->flush();
    }
}

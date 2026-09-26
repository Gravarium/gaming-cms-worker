<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\ExternalConnectorExecutionSummary;
use App\ExternalConnector\ExternalConnectorHealthRecorder;
use App\Repository\ExternalConnectorHealthStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ExternalConnectorHealthRecorderTest extends TestCase
{
    public function testNotConfiguredSummaryDoesNotQueryOrFlush(): void
    {
        $summary = new ExternalConnectorExecutionSummary(ExternalConnectorTarget::CAPABILITY_MEDIA, []);
        self::assertSame(ExternalConnectorExecutionSummary::STATUS_NOT_CONFIGURED, $summary->status());

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        (new ExternalConnectorHealthRecorder($this->unusedStatusRepository(), $entityManager))
            ->record($summary);
    }

    private function unusedStatusRepository(): ExternalConnectorHealthStatusRepository
    {
        $reflection = new ReflectionClass(ExternalConnectorHealthStatusRepository::class);
        /** @var ExternalConnectorHealthStatusRepository $repository */
        $repository = $reflection->newInstanceWithoutConstructor();

        return $repository;
    }
}

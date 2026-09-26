<?php

declare(strict_types=1);

namespace App\\Tests\\ExternalConnector;

use App\\ExternalConnector\\ConnectorProviderCatalog;
use App\\ExternalConnector\\ConnectorTargetPlanner;
use App\\Repository\\ExternalConnectorTargetRepository;
use Doctrine\\ORM\\EntityManagerInterface;
use PHPUnit\\Framework\\TestCase;
use ReflectionClass;

final class ConnectorTargetPlannerBoundaryTest extends TestCase
{
    public function testAcceptsExactlyTheCurrentCatalogSize(): void
    {
        $planner = $this->planner();
        $choices = array_fill(0, $planner->maximumChoiceCount(), 'unknown-provider');

        self::assertSame(29, $planner->maximumChoiceCount());
        self::assertSame([
            'created' => 0,
            'skipped' => 0,
            'keys' => [],
            'rejected' => false,
        ], $planner->plan($choices));
    }

    public function testRejectsCatalogSizePlusOneBeforeRepositoryOrPersistenceWork(): void
    {
        $planner = $this->planner();
        $providerChoice = array_key_first((new ConnectorProviderCatalog())->indexed());
        if ($providerChoice === null) {
            self::fail('The connector catalog must contain at least one provider.');
        }

        $choices = array_fill(0, $planner->maximumChoiceCount() + 1, $providerChoice);

        self::assertSame([
            'created' => 0,
            'skipped' => 0,
            'keys' => [],
            'rejected' => true,
        ], $planner->plan($choices));
    }

    public function testInRangeUnknownChoicesRemainIgnored(): void
    {
        self::assertSame([
            'created' => 0,
            'skipped' => 0,
            'keys' => [],
            'rejected' => false,
        ], $this->planner()->plan([
            'unknown-provider',
            'UNKNOWN-PROVIDER',
            'unknown-provider',
        ]));
    }

    private function planner(): ConnectorTargetPlanner
    {
        $reflection = new ReflectionClass(ExternalConnectorTargetRepository::class);
        /** @var ExternalConnectorTargetRepository $targets */
        $targets = $reflection->newInstanceWithoutConstructor();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        return new ConnectorTargetPlanner(new ConnectorProviderCatalog(), $targets, $entityManager);
    }
}

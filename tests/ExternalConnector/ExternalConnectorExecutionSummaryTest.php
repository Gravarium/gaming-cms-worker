<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\ExternalConnectorExecutionResult;
use App\ExternalConnector\ExternalConnectorExecutionSummary;
use App\ExternalConnector\ExternalConnectorTargetDefinition;
use PHPUnit\Framework\TestCase;

final class ExternalConnectorExecutionSummaryTest extends TestCase
{
    public function testEmptyResultsAreNotConfigured(): void
    {
        $summary = new ExternalConnectorExecutionSummary(ExternalConnectorTarget::CAPABILITY_MAIL, []);

        self::assertSame(ExternalConnectorExecutionSummary::STATUS_NOT_CONFIGURED, $summary->status());
        self::assertSame(0, $summary->successfulCount());
    }

    public function testAllSuccessfulResultsAreHealthy(): void
    {
        $target = $this->target(true);
        $summary = new ExternalConnectorExecutionSummary(
            ExternalConnectorTarget::CAPABILITY_MAIL,
            [
                ExternalConnectorExecutionResult::success($target),
                ExternalConnectorExecutionResult::success($this->target(false)),
            ],
        );

        self::assertSame(ExternalConnectorExecutionSummary::STATUS_HEALTHY, $summary->status());
        self::assertSame(2, $summary->successfulCount());
    }

    public function testOptionalFailureIsDegradedAndRequiredFailureIsFailed(): void
    {
        $optionalFailure = new ExternalConnectorExecutionSummary(
            ExternalConnectorTarget::CAPABILITY_MAIL,
            [ExternalConnectorExecutionResult::failure($this->target(false))],
        );
        $requiredFailure = new ExternalConnectorExecutionSummary(
            ExternalConnectorTarget::CAPABILITY_MAIL,
            [ExternalConnectorExecutionResult::failure($this->target(true))],
        );

        self::assertSame(ExternalConnectorExecutionSummary::STATUS_FAILED, $optionalFailure->status());
        self::assertSame(ExternalConnectorExecutionSummary::STATUS_FAILED, $requiredFailure->status());
        self::assertSame(0, $optionalFailure->successfulCount());
    }

    public function testMixedSuccessfulAndOptionalFailureIsDegraded(): void
    {
        $summary = new ExternalConnectorExecutionSummary(
            ExternalConnectorTarget::CAPABILITY_MAIL,
            [
                ExternalConnectorExecutionResult::success($this->target(true)),
                ExternalConnectorExecutionResult::failure($this->target(false)),
            ],
        );

        self::assertSame(ExternalConnectorExecutionSummary::STATUS_DEGRADED, $summary->status());
        self::assertSame(1, $summary->successfulCount());
    }

    public function testRejectsUnknownCapability(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalConnectorExecutionSummary('unknown', []);
    }

    public function testRejectsAssociativeResultArrays(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalConnectorExecutionSummary(
            ExternalConnectorTarget::CAPABILITY_MAIL,
            ['primary' => ExternalConnectorExecutionResult::success($this->target(true))],
        );
    }

    public function testRejectsNonResultEntries(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalConnectorExecutionSummary(
            ExternalConnectorTarget::CAPABILITY_MAIL,
            [new \stdClass()],
        );
    }

    public function testRejectsOversizedResultLists(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $result = ExternalConnectorExecutionResult::success($this->target(true));
        new ExternalConnectorExecutionSummary(
            ExternalConnectorTarget::CAPABILITY_MAIL,
            array_fill(0, 101, $result),
        );
    }

    private function target(bool $required): ExternalConnectorTargetDefinition
    {
        return new ExternalConnectorTargetDefinition(
            ExternalConnectorTarget::CAPABILITY_MAIL,
            $required ? 'required' : 'optional',
            'symfony-mailer',
            'Mailer',
            $required,
            10,
            'mailer.default',
        );
    }
}

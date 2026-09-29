<?php

declare(strict_types=1);

namespace App\ExternalConnector;

use App\Entity\ExternalConnectorTarget;

final readonly class ExternalConnectorExecutionSummary
{
    public const STATUS_NOT_CONFIGURED = 'not_configured';
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_FAILED = 'failed';

    private const MAX_RESULTS = 100;

    /** @param list<ExternalConnectorExecutionResult> $results */
    public function __construct(
        public string $capability,
        public array $results,
    ) {
        if (!self::validResults($capability, $results)) {
            throw new \InvalidArgumentException('External connector execution summary is invalid.');
        }
    }

    public function status(): string
    {
        if ($this->results === []) {
            return self::STATUS_NOT_CONFIGURED;
        }

        $successes = 0;
        $optionalFailure = false;
        foreach ($this->results as $result) {
            if ($result->successful) {
                ++$successes;
                continue;
            }

            if ($result->required) {
                return self::STATUS_FAILED;
            }

            $optionalFailure = true;
        }

        if ($successes === 0) {
            return self::STATUS_FAILED;
        }

        return $optionalFailure ? self::STATUS_DEGRADED : self::STATUS_HEALTHY;
    }

    public function successfulCount(): int
    {
        return count(array_filter(
            $this->results,
            static fn (ExternalConnectorExecutionResult $result): bool => $result->successful,
        ));
    }

    /** @param array<mixed> $results */
    private static function validResults(string $capability, array $results): bool
    {
        if (!in_array($capability, ExternalConnectorTarget::CAPABILITIES, true)
            || !array_is_list($results)
            || count($results) > self::MAX_RESULTS
        ) {
            return false;
        }

        foreach ($results as $result) {
            if (!$result instanceof ExternalConnectorExecutionResult) {
                return false;
            }
        }

        return true;
    }
}

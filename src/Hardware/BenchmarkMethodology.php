<?php

declare(strict_types=1);

namespace App\Hardware;

final readonly class BenchmarkMethodology
{
    /** @param array<string, string> $testSystem */
    public function __construct(
        public string $name,
        public string $version,
        public string $procedure,
        public array $testSystem,
        public string $disclosure,
    ) {
        if (trim($name) === '' || trim($version) === '' || trim($procedure) === '' || trim($disclosure) === '') {
            throw new \InvalidArgumentException('Benchmark methodology, version, procedure and disclosure are required.');
        }
        if (count($testSystem) < 1 || count($testSystem) > 100 || mb_strlen($procedure) > 10_000) {
            throw new \InvalidArgumentException('Test-system and procedure bounds exceeded.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\ExternalConnector\ExternalConnectorExecutionResult;
use App\ExternalConnector\ExternalConnectorTargetDefinition;
use PHPUnit\Framework\TestCase;

final class ExternalConnectorExecutionResultTest extends TestCase
{
    public function testPreservesSuccessFailureAndRequiredSemantics(): void
    {
        $requiredTarget = $this->target('primary', 'restic', true);
        $optionalTarget = $this->target('archive', 's3', false);

        $success = ExternalConnectorExecutionResult::success($requiredTarget);
        $failure = ExternalConnectorExecutionResult::failure($optionalTarget);

        self::assertSame('primary', $success->targetKey);
        self::assertSame('restic', $success->providerKey);
        self::assertTrue($success->required);
        self::assertTrue($success->successful);

        self::assertSame('archive', $failure->targetKey);
        self::assertSame('s3', $failure->providerKey);
        self::assertFalse($failure->required);
        self::assertFalse($failure->successful);
    }

    public function testRejectsUnsafeTargetKeys(): void
    {
        foreach ([
            '',
            'Primary',
            'primary/key',
            'primary'."\x00",
            "\xFF",
            str_repeat('a', 65),
        ] as $key) {
            $thrown = false;
            try {
                ExternalConnectorExecutionResult::success($this->target($key, 'restic', true));
            } catch (\InvalidArgumentException) {
                $thrown = true;
            }

            self::assertTrue($thrown, 'Unsafe target key was accepted.');
        }
    }

    public function testRejectsUnsafeProviderKeys(): void
    {
        foreach ([
            '',
            'Restic',
            'provider/key',
            'restic'."\x00",
            "\xFF",
            str_repeat('p', 65),
        ] as $key) {
            $thrown = false;
            try {
                ExternalConnectorExecutionResult::failure($this->target('primary', $key, false));
            } catch (\InvalidArgumentException) {
                $thrown = true;
            }

            self::assertTrue($thrown, 'Unsafe provider key was accepted.');
        }
    }

    private function target(string $targetKey, string $providerKey, bool $required): ExternalConnectorTargetDefinition
    {
        return new ExternalConnectorTargetDefinition(
            'backup',
            $targetKey,
            $providerKey,
            'Backup target',
            $required,
            10,
            'backup.primary',
        );
    }
}

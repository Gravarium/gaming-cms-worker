<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ExternalConnectorTarget;
use PHPUnit\Framework\TestCase;

final class ExternalConnectorTargetDisplayNameBoundaryTest extends TestCase
{
    public function testDisplayNameAcceptsExactAsciiAndFourByteUtf8Boundaries(): void
    {
        $asciiName = str_repeat('a', 120);
        self::assertSame($asciiName, (new ExternalConnectorTarget())->setDisplayName($asciiName)->getDisplayName());

        $multibyteName = str_repeat('🎮', 120);
        self::assertSame(120, mb_strlen($multibyteName, 'UTF-8'));
        self::assertSame(480, strlen($multibyteName));
        self::assertSame($multibyteName, (new ExternalConnectorTarget())->setDisplayName($multibyteName)->getDisplayName());
    }

    public function testTrimmedDisplayNameAtTheBoundaryPreservesExistingNormalization(): void
    {
        $name = str_repeat('N', 120);
        $target = new ExternalConnectorTarget();

        self::assertSame($name, $target->setDisplayName("  ".$name." \t")->getDisplayName());
        self::assertSame('External storage', $target->setDisplayName('  External storage  ')->getDisplayName());
    }

    public function testRejectedDisplayNamesPreserveThePreviousValue(): void
    {
        $target = (new ExternalConnectorTarget())->setDisplayName('External storage');

        foreach ([str_repeat('a', 121), str_repeat('é', 121), str_repeat('🎮', 121), "\xFFname", "name\0suffix", "\0name"] as $candidate) {
            $this->assertRejectedWithoutChangingDisplayName($target, $candidate);
        }
    }

    private function assertRejectedWithoutChangingDisplayName(ExternalConnectorTarget $target, string $candidate): void
    {
        $previousName = $target->getDisplayName();

        try {
            $target->setDisplayName($candidate);
        } catch (\InvalidArgumentException) {
            self::assertSame($previousName, $target->getDisplayName());

            return;
        }

        self::fail('An invalid or out-of-bound display name must be rejected.');
    }
}

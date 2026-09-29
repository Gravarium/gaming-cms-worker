<?php

declare(strict_types=1);

namespace App\Tests\Hardware\Workflow;

use App\Hardware\HardwareSpecificationSet;
use PHPUnit\Framework\TestCase;

final class HardwareDomainTest extends TestCase
{
    public function testSpecificationJsonKeepsTypedValuesAndRejectsCompoundValues(): void
    {
        $specifications = (new HardwareSpecificationSet())->decode('{"memory":{"value":16,"unit":"GB"},"ray_tracing":{"value":true}}');

        self::assertCount(2, $specifications);
        self::assertSame(16, $specifications[0]->value);
        self::assertTrue($specifications[1]->value);
        $this->expectException(\InvalidArgumentException::class);
        (new HardwareSpecificationSet())->decode('{"memory":{"value":[],"unit":"GB"}}');
    }

}

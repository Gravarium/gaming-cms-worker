<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MenuItem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class MenuItemSecurityTest extends TestCase
{
    public function testExternalMenuUrlRejectsActiveSchemes(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $item = (new MenuItem())->setLabel('Unsafe')->setUrl('javascript:alert(1)');

        self::assertGreaterThan(0, $validator->validate($item)->count());
    }

    public function testHttpAndHttpsMenuUrlsRemainAllowed(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        foreach (['https://example.test/path', 'http://example.test/path'] as $url) {
            $item = (new MenuItem())->setLabel('Safe')->setUrl($url);
            self::assertCount(0, $validator->validate($item));
        }
    }
}

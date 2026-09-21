<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MenuItem;
use PHPUnit\Framework\TestCase;

final class MenuItemSecurityTest extends TestCase
{
    public function testAcceptsHttpAndHttpsExternalUrls(): void
    {
        self::assertSame('https://example.test/path', (new MenuItem())->setUrl('https://example.test/path')->getUrl());
        self::assertSame('http://example.test/path', (new MenuItem())->setUrl('http://example.test/path')->getUrl());
    }

    public function testRejectsActiveSchemesAndEmbeddedCredentials(): void
    {
        foreach ([
            'javascript:alert(1)',
            'data:text/html,test',
            'https://user:secret@example.test/path',
            '//example.test/path',
        ] as $url) {
            try {
                (new MenuItem())->setUrl($url);
                self::fail('Unsafe menu URL accepted: '.$url);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}

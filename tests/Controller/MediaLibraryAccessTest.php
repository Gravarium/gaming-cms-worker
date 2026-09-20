<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MediaLibraryAccessTest extends WebTestCase
{
    #[DataProvider('protectedRoutes')]
    public function testMediaManagementRequiresLogin(string $method, string $path): void
    {
        $client = static::createClient();
        $client->request($method, $path);
        self::assertResponseRedirects('/login');
    }

    /** @return iterable<string, array{string, string}> */
    public static function protectedRoutes(): iterable
    {
        yield 'library' => ['GET', '/admin/storage'];
        yield 'upload' => ['GET', '/admin/storage/media/upload'];
        yield 'new folder' => ['GET', '/admin/storage/folders/new'];
        yield 'bulk action' => ['POST', '/admin/storage/media/bulk'];
    }
}

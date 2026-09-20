<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StorageAccessTest extends WebTestCase
{
    public function testStorageAdministrationRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/storage');
        self::assertResponseRedirects('/login');
    }

    public function testMediaUploadRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/storage/media/upload');
        self::assertResponseRedirects('/login');
    }

    public function testMediaDeleteRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('POST', '/admin/storage/media/1/delete');
        self::assertResponseRedirects('/login');
    }
}

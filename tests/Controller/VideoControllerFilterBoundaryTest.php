<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoControllerFilterBoundaryTest extends WebTestCase
{
    public function testOverlongCategoryFilterReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/videos', ['category' => str_repeat('x', 141)]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testOverlongPlaylistFilterReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/videos', ['playlist' => str_repeat('x', 181)]);

        self::assertResponseStatusCodeSame(404);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicNewsFeedsPageTest extends WebTestCase
{
    public function testPublicDirectoryLinksToSupportedNewsFeedFormats(): void
    {
        $client = static::createClient();
        $client->request('GET', '/news/feeds');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'News abonnieren');
        self::assertSelectorExists('a[href="/feeds/news.xml"]');
        self::assertSelectorExists('a[href="/feeds/news.json"]');
        self::assertSelectorExists('a[href="/feeds/news.atom"]');
        self::assertSelectorExists('a[href="/news"]');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomePortalAccessibilityTest extends WebTestCase
{
    public function testPublicHomeSkipLinkTargetsOneMainLandmark(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        $skipLink = $crawler->filter('a.portal-skip[href="#portal-content"]');
        self::assertCount(1, $skipLink);
        self::assertSame('Zum Inhalt', trim($skipLink->text()));

        self::assertCount(1, $crawler->filter('#portal-content'));
        self::assertSame(1, $crawler->filter('main#portal-content')->count());
    }
}

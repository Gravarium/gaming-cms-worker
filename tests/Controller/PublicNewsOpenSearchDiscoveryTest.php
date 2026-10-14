<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicNewsOpenSearchDiscoveryTest extends WebTestCase
{
    public function testHomepageAdvertisesThePublicNewsOpenSearchDescription(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('head link[rel="search"][type="application/opensearchdescription+xml"]');
        self::assertSelectorExists('head link[rel="search"][href="http://localhost/news/opensearch.xml"]');
    }
}

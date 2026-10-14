<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicNewsOpenSearchTest extends WebTestCase
{
    public function testDescriptionAdvertisesOnlyPublicNewsSearchAndSupportsConditionalRequests(): void
    {
        $client = static::createClient();
        $client->request('GET', '/news/opensearch.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/opensearchdescription+xml; charset=UTF-8');
        self::assertStringContainsString('max-age=3600', (string) $client->getResponse()->headers->get('Cache-Control'));
        $xml = $client->getResponse()->getContent();
        self::assertIsString($xml);
        self::assertStringContainsString('<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/">', $xml);
        self::assertStringContainsString('template="http://localhost/search?type=news&amp;q={searchTerms}"', $xml);
        self::assertStringNotContainsString('type=page', $xml);

        $etag = $client->getResponse()->headers->get('ETag');
        self::assertIsString($etag);
        $client->request('GET', '/news/opensearch.xml', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RobotsTxtControllerTest extends WebTestCase
{
    public function testAnonymousGetReturnsMinimalRobotsFileAndSameOriginSitemap(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt', [], [], [
            'HTTP_HOST' => 'cms.example.test',
            'HTTPS' => 'on',
        ]);

        self::assertResponseIsSuccessful();

        $response = $client->getResponse();
        $content = $response->getContent();
        self::assertIsString($content);
        self::assertSame(
            "User-agent: *\nDisallow: /admin\nSitemap: https://cms.example.test/sitemap.xml\n",
            $content,
        );
        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $cacheControl = strtolower($response->headers->get('Cache-Control', ''));
        self::assertMatchesRegularExpression('/(?:^|,\s*)public(?:,|$)/', $cacheControl);
        self::assertMatchesRegularExpression('/(?:^|,\s*)max-age=300(?:,|$)/', $cacheControl);
        self::assertMatchesRegularExpression('/(?:^|,\s*)s-maxage=300(?:,|$)/', $cacheControl);
    }

    public function testPostIsNotAllowed(): void
    {
        $client = static::createClient();
        $client->request('POST', '/robots.txt');

        self::assertResponseStatusCodeSame(405);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Search\SearchDocument;
use App\Search\SearchIndexRecord;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class SearchPaginationTest extends WebTestCase
{
    public function testSearchDiscoveryAndFeedsKeepVisibleResultsAcrossPages(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $connection = $this->connection($client);
        $token = bin2hex(random_bytes(8));
        $sourceType = 'wcp559_'.$token;
        $sourceDate = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $titles = [];

        try {
            for ($index = 1; $index <= 23; ++$index) {
                $title = sprintf('WCP559 public %s %02d', $token, $index);
                $titles[] = $title;
                $entityManager->persist(new SearchDocument($this->record(
                    $sourceType,
                    $index,
                    'content',
                    SearchDocument::VISIBILITY_PUBLIC,
                    $title,
                    $sourceDate,
                )));
            }
            $entityManager->persist(new SearchDocument($this->record(
                $sourceType,
                24,
                'content',
                SearchDocument::VISIBILITY_OWNER_OR_MODERATOR,
                'WCP559 hidden '.$token,
                $sourceDate->modify('+1 day'),
                50,
            )));
            $entityManager->persist(new SearchDocument($this->record(
                $sourceType,
                25,
                'gaming',
                SearchDocument::VISIBILITY_PUBLIC,
                'WCP559 other module '.$token,
                $sourceDate->modify('+2 days'),
                100,
            )));
            $entityManager->flush();

            $client->request('GET', '/search/global?q=shared&module=content&type=news');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.global-search-count', '23 sichtbare Treffer');
            self::assertSelectorTextContains('.global-search-count', 'Seite 1 von 2');
            self::assertSelectorCount(20, '.search-result');
            $firstPageTitles = $client->getCrawler()->filter('.search-result h2')->each(
                static fn (Crawler $node): string => trim($node->text()),
            );
            self::assertSame(array_slice($titles, 0, 20), $firstPageTitles);
            self::assertStringNotContainsString('WCP559 hidden '.$token, (string) $client->getResponse()->getContent());

            $nextUrl = (string) $client->getCrawler()->filter('.global-search-pagination a[rel="next"]')->attr('href');
            $queryString = parse_url($nextUrl, PHP_URL_QUERY);
            self::assertIsString($queryString);
            parse_str($queryString, $nextParameters);
            self::assertSame('shared', $nextParameters['q'] ?? null);
            self::assertSame('content', $nextParameters['module'] ?? null);
            self::assertSame('news', $nextParameters['type'] ?? null);
            self::assertSame('2', $nextParameters['page'] ?? null);

            $client->request('GET', $nextUrl);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.global-search-count', '23 sichtbare Treffer');
            self::assertSelectorTextContains('.global-search-count', 'Seite 2 von 2');
            self::assertSelectorCount(3, '.search-result');
            $lastPageTitles = $client->getCrawler()->filter('.search-result h2')->each(
                static fn (Crawler $node): string => trim($node->text()),
            );
            self::assertSame(array_slice($titles, 20), $lastPageTitles);
            self::assertSelectorExists('.global-search-pagination a[rel="prev"]');
            self::assertSelectorNotExists('.global-search-pagination a[rel="next"]');

            $client->request('GET', '/search/global?q=shared&module=content&type=news&page=999');
            self::assertResponseRedirects('/search/global?q=shared&module=content&type=news&page=2');

            $client->request('GET', '/search/global?module=content&type=news&page=2');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.global-search-count', '23 sichtbare Empfehlungen');
            self::assertSelectorTextContains('.global-search-count', 'Seite 2 von 2');
            self::assertCount(3, $client->getCrawler()->filter('.search-result'));

            $client->request('GET', '/search/global.json?q=shared&module=content&type=news&page=2');
            self::assertResponseIsSuccessful();
            self::assertSame('private, no-store', $client->getResponse()->headers->get('Cache-Control'));
            $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertArrayHasKey('items', $payload);
            self::assertIsArray($payload['items']);
            self::assertCount(23, $payload['items']);
            self::assertStringNotContainsString('WCP559 hidden '.$token, (string) $client->getResponse()->getContent());

            $client->request('GET', '/feeds/discovery.xml?q=shared&module=content&type=news&page=2');
            self::assertResponseIsSuccessful();
            self::assertSame('application/rss+xml; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
            self::assertSame(23, substr_count((string) $client->getResponse()->getContent(), '<item>'));
            self::assertStringNotContainsString('WCP559 hidden '.$token, (string) $client->getResponse()->getContent());
        } finally {
            $connection->executeStatement('DELETE FROM search_document WHERE source_type = :source', ['source' => $sourceType]);
        }
    }

    public function testInvalidPageInputsFailClosed(): void
    {
        $client = static::createClient();
        $invalidUrls = [
            '/search/global?q=shared&page=0',
            '/search/global?q=shared&page=-1',
            '/search/global?q=shared&page=abc',
            '/search/global?q=shared&page=1001',
            '/search/global?q=shared&page=999999999999999999999999',
            '/search/global?q=shared&page%5B%5D=2',
            '/search/global?q=shared&page=',
        ];

        foreach ($invalidUrls as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(400, 'Invalid page input should be rejected: '.$url);
        }
    }

    private function record(
        string $sourceType,
        int $sourceId,
        string $moduleKey,
        string $visibility,
        string $title,
        \DateTimeImmutable $updatedAt,
        int $popularity = 0,
    ): SearchIndexRecord {
        return new SearchIndexRecord(
            $sourceType,
            $sourceId,
            $moduleKey,
            'news',
            $title,
            'shared body for pagination',
            'fixture excerpt',
            '/search-fixture/'.$sourceType.'/'.$sourceId,
            $visibility,
            $visibility === SearchDocument::VISIBILITY_OWNER_OR_MODERATOR ? 999 : null,
            null,
            ['fixture'],
            $popularity,
            false,
            $updatedAt,
        );
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get('doctrine')->getManager();
    }

    private function connection(KernelBrowser $client): Connection
    {
        return $client->getContainer()->get('doctrine')->getConnection();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicNewsSearchSuggestionsTest extends WebTestCase
{
    public function testSuggestionsContainOnlyPublicNewsAndAreBoundedAndCacheable(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $author = (new User())->setEmail('news-suggest-'.$suffix.'@example.test')
            ->setDisplayName('News suggestions author')->verifyEmail();
        $category = (new Category())->setName('Suggestions '.$suffix)->setSlug('suggestions-'.$suffix);
        $entities = [$author, $category];

        for ($index = 1; $index <= 7; ++$index) {
            $entities[] = $this->entry($author, $category, 'newsquery-'.$suffix.' result '.$index, 'suggestion-'.$suffix.'-'.$index, ContentEntry::STATUS_PUBLISHED);
        }
        $entities[] = $this->entry($author, $category, 'newsquery-'.$suffix.' private draft', 'suggestion-'.$suffix.'-draft', ContentEntry::STATUS_DRAFT);
        $entities[] = $this->entry($author, $category, 'newsquery-'.$suffix.' unlisted', 'suggestion-'.$suffix.'-unlisted', ContentEntry::STATUS_PUBLISHED, true);

        foreach ($entities as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $query = 'newsquery-'.$suffix;
        $client->request('GET', '/news/search-suggestions.json', ['q' => $query]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertStringContainsString('max-age=30', (string) $client->getResponse()->headers->get('Cache-Control'));
        $response = $client->getResponse()->getContent();
        self::assertIsString($response);
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($query, $data['query']);
        self::assertCount(5, $data['suggestions']);
        self::assertStringNotContainsString('private draft', $response);
        self::assertStringNotContainsString('unlisted', $response);
        self::assertStringNotContainsString('Private body', $response);
        foreach ($data['suggestions'] as $suggestion) {
            self::assertSame(['title', 'url'], array_keys($suggestion));
            self::assertStringStartsWith('http://localhost/news/', $suggestion['url']);
        }

        $etag = $client->getResponse()->headers->get('ETag');
        self::assertIsString($etag);
        $client->request('GET', '/news/search-suggestions.json', ['q' => $query], server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertResponseStatusCodeSame(304);
    }

    public function testInvalidQueryShapesAreRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/news/search-suggestions.json', ['q' => str_repeat('x', 81)]);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/news/search-suggestions.json', ['q' => ['news', 'page']]);
        self::assertResponseStatusCodeSame(404);
    }

    private function entry(
        User $author,
        Category $category,
        string $title,
        string $slug,
        string $status,
        bool $unlisted = false,
    ): ContentEntry {
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setCategory($category)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle($title)
            ->setSlug($slug)
            ->setBody('Private body for '.$title)
            ->setStatus($status)
            ->setUnlisted($unlisted);
        $entry->synchronizePublication();

        return $entry;
    }
}

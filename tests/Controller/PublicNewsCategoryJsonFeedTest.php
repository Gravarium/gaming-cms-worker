<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicNewsCategoryJsonFeedTest extends WebTestCase
{
    public function testCategoryJsonFeedContainsOnlyPublicNewsAndSupportsConditionalRequests(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $author = (new User())->setEmail('category-json-feed-'.$suffix.'@example.test')
            ->setDisplayName('JSON feed author')->verifyEmail();
        $category = (new Category())->setName('Category '.$suffix)->setSlug('json-category-'.$suffix)
            ->setDescription('Topic description');
        $otherCategory = (new Category())->setName('Other '.$suffix)->setSlug('json-other-'.$suffix);
        $tag = (new ContentTag())->setName('Feature '.$suffix)->setSlug('feature-'.$suffix);
        $listed = $this->entry($author, $category, $tag, 'Listed '.$suffix, 'json-listed-'.$suffix, ContentEntry::STATUS_PUBLISHED);
        $unlisted = $this->entry($author, $category, $tag, 'Hidden '.$suffix, 'json-hidden-'.$suffix, ContentEntry::STATUS_PUBLISHED, true);
        $draft = $this->entry($author, $category, $tag, 'Draft '.$suffix, 'json-draft-'.$suffix, ContentEntry::STATUS_DRAFT);
        $other = $this->entry($author, $otherCategory, $tag, 'Other category '.$suffix, 'json-other-'.$suffix, ContentEntry::STATUS_PUBLISHED);

        foreach ([$author, $category, $otherCategory, $tag, $listed, $unlisted, $draft, $other] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $path = '/news/category/json-category-'.$suffix.'/feed.json';
        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/feed+json; charset=UTF-8');
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=60', $cacheControl);
        self::assertStringContainsString('s-maxage=60', $cacheControl);

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        $feed = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('https://jsonfeed.org/version/1.1', $feed['version']);
        self::assertSame('Category '.$suffix, $feed['title']);
        self::assertSame('Topic description', $feed['description']);
        self::assertStringStartsWith('http', $feed['home_page_url']);
        self::assertStringStartsWith('http', $feed['feed_url']);
        self::assertCount(1, $feed['items']);
        self::assertSame('Listed '.$suffix, $feed['items'][0]['title']);
        self::assertSame(['Feature '.$suffix], $feed['items'][0]['tags']);
        self::assertSame($feed['items'][0]['url'], $feed['items'][0]['id']);
        self::assertStringStartsWith('http', $feed['items'][0]['url']);
        self::assertArrayNotHasKey('content_text', $feed['items'][0]);
        self::assertArrayNotHasKey('content_html', $feed['items'][0]);

        $etag = $client->getResponse()->headers->get('ETag');
        self::assertNotEmpty($etag);
        $client->request('GET', $path, server: ['HTTP_IF_NONE_MATCH' => '*']);
        self::assertResponseStatusCodeSame(304);

        $client->request('GET', '/news/category/missing-'.$suffix.'/feed.json');
        self::assertResponseStatusCodeSame(404);
    }

    private function entry(
        User $author,
        Category $category,
        ContentTag $tag,
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
            ->setExcerpt('Summary '.$title)
            ->setBody('Private body '.$title)
            ->setStatus($status)
            ->setUnlisted($unlisted);
        $entry->addTag($tag);
        $entry->synchronizePublication();

        return $entry;
    }
}

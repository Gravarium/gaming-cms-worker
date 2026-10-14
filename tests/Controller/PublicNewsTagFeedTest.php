<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicNewsTagFeedTest extends WebTestCase
{
    public function testTagFeedContainsOnlyListedPublishedNewsWithThatTagAndSupportsEtags(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $author = (new User())->setEmail('tag-feed-'.$suffix.'@example.test')
            ->setDisplayName('Tag feed author')->verifyEmail();
        $category = (new Category())->setName('Category '.$suffix)->setSlug('category-'.$suffix);
        $tag = (new ContentTag())->setName('Topic <'.$suffix.'>')->setSlug('topic-'.$suffix)->setDescription('Topic & notes');
        $otherTag = (new ContentTag())->setName('Other '.$suffix)->setSlug('other-'.$suffix);
        $listed = $this->entry($author, $category, $tag, 'Tagged <News> & more '.$suffix, 'tagged-'.$suffix, ContentEntry::STATUS_PUBLISHED);
        $hidden = $this->entry($author, $category, $tag, 'Hidden '.$suffix, 'hidden-'.$suffix, ContentEntry::STATUS_PUBLISHED, true);
        $draft = $this->entry($author, $category, $tag, 'Draft '.$suffix, 'draft-'.$suffix, ContentEntry::STATUS_DRAFT);
        $otherTagEntry = $this->entry($author, $category, $otherTag, 'Other tag '.$suffix, 'other-tag-'.$suffix, ContentEntry::STATUS_PUBLISHED);

        foreach ([$author, $category, $tag, $otherTag, $listed, $hidden, $draft, $otherTagEntry] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $path = '/news/tag/topic-'.$suffix.'/feed.xml';
        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/rss+xml; charset=UTF-8');
        self::assertStringContainsString('max-age=60', (string) $client->getResponse()->headers->get('Cache-Control'));
        $feed = $client->getResponse()->getContent();
        self::assertIsString($feed);
        self::assertStringContainsString('Tagged &lt;News&gt; &amp; more '.$suffix, $feed);
        self::assertStringContainsString('Topic &lt;'.$suffix.'&gt;', $feed);
        self::assertStringNotContainsString('Hidden '.$suffix, $feed);
        self::assertStringNotContainsString('Draft '.$suffix, $feed);
        self::assertStringNotContainsString('Other tag '.$suffix, $feed);

        $client->request('GET', $path, server: ['HTTP_IF_NONE_MATCH' => '*']);
        self::assertResponseStatusCodeSame(304);
        $client->request('GET', '/news/tag/missing-'.$suffix.'/feed.xml');
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

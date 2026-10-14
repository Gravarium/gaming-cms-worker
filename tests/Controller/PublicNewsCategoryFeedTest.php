<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicNewsCategoryFeedTest extends WebTestCase
{
    public function testCategoryFeedOnlyContainsPublicNewsForThatCategory(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $author = (new User())->setEmail('category-feed-'.$suffix.'@example.test')
            ->setDisplayName('Category feed author')->verifyEmail();
        $category = (new Category())->setName('Category <'.$suffix.'>')->setSlug('category-'.$suffix)
            ->setDescription('Topic & notes');
        $otherCategory = (new Category())->setName('Other '.$suffix)->setSlug('other-'.$suffix);
        $listed = $this->entry($author, $category, 'News <One> & more '.$suffix, 'category-news-'.$suffix, ContentEntry::STATUS_PUBLISHED);
        $unlisted = $this->entry($author, $category, 'Hidden '.$suffix, 'category-hidden-'.$suffix, ContentEntry::STATUS_PUBLISHED, true);
        $draft = $this->entry($author, $category, 'Draft '.$suffix, 'category-draft-'.$suffix, ContentEntry::STATUS_DRAFT);
        $other = $this->entry($author, $otherCategory, 'Other category '.$suffix, 'other-news-'.$suffix, ContentEntry::STATUS_PUBLISHED);

        $entityManager->persist($author);
        $entityManager->persist($category);
        $entityManager->persist($otherCategory);
        foreach ([$listed, $unlisted, $draft, $other] as $entry) {
            $entityManager->persist($entry);
        }
        $entityManager->flush();

        $client->request('GET', '/news/category/category-'.$suffix.'/feed.xml');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/rss+xml; charset=UTF-8');
        self::assertStringContainsString('public', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=60', (string) $client->getResponse()->headers->get('Cache-Control'));
        $feed = $client->getResponse()->getContent();
        self::assertIsString($feed);
        self::assertStringContainsString('News &lt;One&gt; &amp; more '.$suffix, $feed);
        self::assertStringContainsString('Category &lt;'.$suffix.'&gt;', $feed);
        self::assertStringNotContainsString('Hidden '.$suffix, $feed);
        self::assertStringNotContainsString('Draft '.$suffix, $feed);
        self::assertStringNotContainsString('Other category '.$suffix, $feed);

        $client->request('GET', '/news/category/missing-'.$suffix.'/feed.xml');
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
            ->setExcerpt($title)
            ->setBody($title)
            ->setStatus($status)
            ->setUnlisted($unlisted);
        $entry->synchronizePublication();

        return $entry;
    }
}

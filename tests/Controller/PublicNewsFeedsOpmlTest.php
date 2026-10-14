<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicNewsFeedsOpmlTest extends WebTestCase
{
    public function testOpmlListsOnlyTaxonomiesWithPublicNewsAndSupportsConditionalRequests(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $author = (new User())->setEmail('news-opml-'.$suffix.'@example.test')
            ->setDisplayName('News OPML author')->verifyEmail();
        $publicCategory = (new Category())->setName('Category <'.$suffix.'> &')->setSlug('opml-category-'.$suffix);
        $draftCategory = (new Category())->setName('Draft category '.$suffix)->setSlug('opml-draft-category-'.$suffix);
        $publicTag = (new ContentTag())->setName('Tag <'.$suffix.'> &')->setSlug('opml-tag-'.$suffix);
        $draftTag = (new ContentTag())->setName('Draft tag '.$suffix)->setSlug('opml-draft-tag-'.$suffix);
        $publicEntry = $this->entry($author, $publicCategory, 'Public '.$suffix, 'opml-public-'.$suffix, ContentEntry::STATUS_PUBLISHED);
        $publicEntry->addTag($publicTag);
        $draftEntry = $this->entry($author, $draftCategory, 'Draft '.$suffix, 'opml-draft-'.$suffix, ContentEntry::STATUS_DRAFT);
        $draftEntry->addTag($draftTag);
        $hiddenEntry = $this->entry($author, $draftCategory, 'Hidden '.$suffix, 'opml-hidden-'.$suffix, ContentEntry::STATUS_PUBLISHED, true);
        $hiddenEntry->addTag($draftTag);

        foreach ([$author, $publicCategory, $draftCategory, $publicTag, $draftTag, $publicEntry, $draftEntry, $hiddenEntry] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $path = '/news/feeds.opml';
        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/x-opml; charset=UTF-8');
        self::assertStringContainsString('max-age=60', (string) $client->getResponse()->headers->get('Cache-Control'));
        $xml = $client->getResponse()->getContent();
        self::assertIsString($xml);
        self::assertStringContainsString('<opml version="2.0">', $xml);
        self::assertStringContainsString('Category &lt;'.$suffix.'&gt; &amp;', $xml);
        self::assertStringContainsString('Tag &lt;'.$suffix.'&gt; &amp;', $xml);
        self::assertStringContainsString('/news/category/opml-category-'.$suffix.'/feed.xml', $xml);
        self::assertStringContainsString('/news/tag/opml-tag-'.$suffix.'/feed.xml', $xml);
        self::assertStringNotContainsString('opml-draft-category-'.$suffix, $xml);
        self::assertStringNotContainsString('opml-draft-tag-'.$suffix, $xml);

        $client->request('GET', $path, server: ['HTTP_IF_NONE_MATCH' => '*']);
        self::assertResponseStatusCodeSame(304);
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
            ->setBody('Private body '.$title)
            ->setStatus($status)
            ->setUnlisted($unlisted);
        $entry->synchronizePublication();

        return $entry;
    }
}

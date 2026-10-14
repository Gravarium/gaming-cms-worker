<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicNewsTaxonomySitemapTest extends WebTestCase
{
    public function testSitemapIncludesOnlyTaxonomiesWithPublicNewsAndSupportsEtags(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $author = (new User())->setEmail('taxonomy-sitemap-'.$suffix.'@example.test')
            ->setDisplayName('Taxonomy sitemap author')->verifyEmail();
        $publicCategory = (new Category())->setName('Public '.$suffix)->setSlug('public-cat-'.$suffix);
        $draftCategory = (new Category())->setName('Draft '.$suffix)->setSlug('draft-cat-'.$suffix);
        $emptyCategory = (new Category())->setName('Empty '.$suffix)->setSlug('empty-cat-'.$suffix);
        $publicTag = (new ContentTag())->setName('Public '.$suffix)->setSlug('public-tag-'.$suffix);
        $draftTag = (new ContentTag())->setName('Draft '.$suffix)->setSlug('draft-tag-'.$suffix);
        $emptyTag = (new ContentTag())->setName('Empty '.$suffix)->setSlug('empty-tag-'.$suffix);
        $publicEntry = $this->entry($author, $publicCategory, 'Public news '.$suffix, 'public-news-'.$suffix, ContentEntry::STATUS_PUBLISHED);
        $publicEntry->addTag($publicTag);
        $draftEntry = $this->entry($author, $draftCategory, 'Draft news '.$suffix, 'draft-news-'.$suffix, ContentEntry::STATUS_DRAFT);
        $draftEntry->addTag($draftTag);
        $hiddenEntry = $this->entry($author, $draftCategory, 'Hidden news '.$suffix, 'hidden-news-'.$suffix, ContentEntry::STATUS_PUBLISHED, true);
        $hiddenEntry->addTag($draftTag);

        foreach ([$author, $publicCategory, $draftCategory, $emptyCategory, $publicTag, $draftTag, $emptyTag, $publicEntry, $draftEntry, $hiddenEntry] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $client->request('GET', '/news-taxonomy-sitemap.xml');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
        self::assertStringContainsString('max-age=60', (string) $client->getResponse()->headers->get('Cache-Control'));
        $xml = $client->getResponse()->getContent();
        self::assertIsString($xml);
        self::assertStringContainsString('/news/category/public-cat-'.$suffix, $xml);
        self::assertStringContainsString('/news/tag/public-tag-'.$suffix, $xml);
        self::assertStringNotContainsString('/news/category/draft-cat-'.$suffix, $xml);
        self::assertStringNotContainsString('/news/category/empty-cat-'.$suffix, $xml);
        self::assertStringNotContainsString('/news/tag/draft-tag-'.$suffix, $xml);
        self::assertStringNotContainsString('/news/tag/empty-tag-'.$suffix, $xml);

        $client->request('GET', '/news-taxonomy-sitemap.xml', server: ['HTTP_IF_NONE_MATCH' => '*']);
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
            ->setBody($title)
            ->setStatus($status)
            ->setUnlisted($unlisted);
        $entry->synchronizePublication();

        return $entry;
    }
}

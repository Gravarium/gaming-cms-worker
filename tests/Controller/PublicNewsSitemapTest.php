<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicNewsSitemapTest extends WebTestCase
{
    public function testSitemapContainsOnlyRecentListedIndexedNewsAndSupportsConditionalRequests(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $author = (new User())->setEmail('news-sitemap-'.$suffix.'@example.test')
            ->setDisplayName('News sitemap author')->verifyEmail();
        $fresh = $this->entry($author, 'Fresh <News> & '.$suffix, 'fresh-'.$suffix)
            ->setPublishedAt(new DateTimeImmutable('-1 hour'));
        $old = $this->entry($author, 'Old '.$suffix, 'old-'.$suffix)
            ->setPublishedAt(new DateTimeImmutable('-49 hours'));
        $noIndex = $this->entry($author, 'Noindex '.$suffix, 'noindex-'.$suffix)
            ->setNoIndex(true);
        $unlisted = $this->entry($author, 'Unlisted '.$suffix, 'unlisted-'.$suffix)
            ->setUnlisted(true);
        $draft = (new ContentEntry())->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)->setTitle('Draft '.$suffix)->setSlug('draft-'.$suffix)
            ->setBody('Draft body')->setStatus(ContentEntry::STATUS_DRAFT);
        $page = $this->entry($author, 'Page '.$suffix, 'page-'.$suffix)
            ->setType(ContentEntry::TYPE_PAGE);

        foreach ([$author, $fresh, $old, $noIndex, $unlisted, $draft, $page] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $client->request('GET', '/news-sitemap.xml');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
        self::assertStringContainsString('max-age=60', (string) $client->getResponse()->headers->get('Cache-Control'));
        $xml = $client->getResponse()->getContent();
        self::assertIsString($xml);
        self::assertStringContainsString('http://www.google.com/schemas/sitemap-news/0.9', $xml);
        self::assertStringContainsString('Fresh &lt;News&gt; &amp; '.$suffix, $xml);
        self::assertStringNotContainsString('Old '.$suffix, $xml);
        self::assertStringNotContainsString('Noindex '.$suffix, $xml);
        self::assertStringNotContainsString('Unlisted '.$suffix, $xml);
        self::assertStringNotContainsString('Draft '.$suffix, $xml);
        self::assertStringNotContainsString('Page '.$suffix, $xml);

        $client->request('GET', '/news-sitemap.xml', server: ['HTTP_IF_NONE_MATCH' => '*']);
        self::assertResponseStatusCodeSame(304);
    }

    private function entry(User $author, string $title, string $slug): ContentEntry
    {
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle($title)
            ->setSlug($slug)
            ->setExcerpt($title)
            ->setBody('Private body '.$title)
            ->setStatus(ContentEntry::STATUS_PUBLISHED);
        $entry->synchronizePublication();

        return $entry;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller\Content;

use App\Entity\ContentEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicContentDiscoveryVisibilityTest extends WebTestCase
{
    public function testFeedsAndSearchExcludeUnlistedAndUnpublishedNews(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $author = (new User())
            ->setEmail('public-discovery-'.$suffix.'@example.test')
            ->setDisplayName('Public discovery author')
            ->verifyEmail();

        $listed = $this->entry($author, 'Listed '.$suffix, 'listed-'.$suffix, ContentEntry::STATUS_PUBLISHED);
        $unlisted = $this->entry($author, 'Unlisted '.$suffix, 'unlisted-'.$suffix, ContentEntry::STATUS_PUBLISHED, true);
        $draft = $this->entry($author, 'Draft '.$suffix, 'draft-'.$suffix, ContentEntry::STATUS_DRAFT);
        $noIndex = $this->entry($author, 'Noindex '.$suffix, 'noindex-'.$suffix, ContentEntry::STATUS_PUBLISHED, false, true);

        $entityManager->persist($author);
        foreach ([$listed, $unlisted, $draft, $noIndex] as $entry) {
            $entityManager->persist($entry);
        }
        $entityManager->flush();

        $client->request('GET', '/feeds/news.xml');
        self::assertResponseIsSuccessful();
        $rss = $client->getResponse()->getContent();
        self::assertIsString($rss);
        self::assertStringContainsString($listed->getTitle(), $rss);
        self::assertStringContainsString($noIndex->getTitle(), $rss);
        self::assertStringNotContainsString($unlisted->getTitle(), $rss);
        self::assertStringNotContainsString($draft->getTitle(), $rss);

        $client->request('GET', '/feeds/news.json');
        self::assertResponseIsSuccessful();
        $json = $client->getResponse()->getContent();
        self::assertIsString($json);
        self::assertStringContainsString($listed->getTitle(), $json);
        self::assertStringContainsString($noIndex->getTitle(), $json);
        self::assertStringNotContainsString($unlisted->getTitle(), $json);
        self::assertStringNotContainsString($draft->getTitle(), $json);

        $client->request('GET', '/search?q='.$suffix);
        self::assertResponseIsSuccessful();
        $search = $client->getResponse()->getContent();
        self::assertIsString($search);
        self::assertStringContainsString($listed->getTitle(), $search);
        self::assertStringContainsString($noIndex->getTitle(), $search);
        self::assertStringNotContainsString($unlisted->getTitle(), $search);
        self::assertStringNotContainsString($draft->getTitle(), $search);
    }

    public function testSitemapExcludesUnlistedUnpublishedAndNoindexNews(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $author = (new User())
            ->setEmail('public-sitemap-'.$suffix.'@example.test')
            ->setDisplayName('Public sitemap author')
            ->verifyEmail();

        $listed = $this->entry($author, 'Listed '.$suffix, 'listed-'.$suffix, ContentEntry::STATUS_PUBLISHED);
        $unlisted = $this->entry($author, 'Unlisted '.$suffix, 'unlisted-'.$suffix, ContentEntry::STATUS_PUBLISHED, true);
        $draft = $this->entry($author, 'Draft '.$suffix, 'draft-'.$suffix, ContentEntry::STATUS_DRAFT);
        $noIndex = $this->entry($author, 'Noindex '.$suffix, 'noindex-'.$suffix, ContentEntry::STATUS_PUBLISHED, false, true);

        $entityManager->persist($author);
        foreach ([$listed, $unlisted, $draft, $noIndex] as $entry) {
            $entityManager->persist($entry);
        }
        $entityManager->flush();

        $client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        $sitemap = $client->getResponse()->getContent();
        self::assertIsString($sitemap);
        self::assertStringContainsString('listed-'.$suffix, $sitemap);
        self::assertStringNotContainsString('unlisted-'.$suffix, $sitemap);
        self::assertStringNotContainsString('draft-'.$suffix, $sitemap);
        self::assertStringNotContainsString('noindex-'.$suffix, $sitemap);
    }

    private function entry(User $author, string $title, string $slug, string $status, bool $unlisted = false, bool $noIndex = false): ContentEntry
    {
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle($title)
            ->setSlug($slug)
            ->setExcerpt($title)
            ->setBody($title)
            ->setStatus($status)
            ->setUnlisted($unlisted)
            ->setNoIndex($noIndex);
        $entry->synchronizePublication();

        return $entry;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicContentRenderingTest extends WebTestCase
{
    public function testPublicNewsDetailEscapesStoredTextAndJsonLdPayloads(): void
    {
        $client = static::createClient();
        $entryId = null;
        $authorId = null;

        try {
            $em = $this->em($client);
            $author = (new User())
                ->setEmail('public-content-rendering-'.bin2hex(random_bytes(6)).'@example.test')
                ->setDisplayName('Public content rendering')
                ->verifyEmail();
            $em->persist($author);
            $em->flush();

            $authorId = $author->getId();
            if ($authorId === null) {
                throw new \LogicException('The synthetic author was not persisted.');
            }

            $suffix = bin2hex(random_bytes(6));
            $title = '"><script>alert("content-title-'.$suffix.'")</script>';
            $subtitle = '<img src=x onerror=alert("content-subtitle-'.$suffix.'")>';
            $excerpt = '"><svg onload=alert("content-excerpt-'.$suffix.'")>';
            $seoTitle = '"><script>alert("content-seo-title-'.$suffix.'")</script>';
            $seoDescription = '"><img src=x onerror=alert("content-seo-description-'.$suffix.'")>';

            $entry = (new ContentEntry())
                ->setAuthor($author)
                ->setType(ContentEntry::TYPE_NEWS)
                ->setTitle($title)
                ->setSubtitle($subtitle)
                ->setSlug('public-content-rendering-'.$suffix)
                ->setExcerpt($excerpt)
                ->setBody('Safe public rendering fixture '.$suffix)
                ->setSeoTitle($seoTitle)
                ->setSeoDescription($seoDescription)
                ->setStatus(ContentEntry::STATUS_PUBLISHED)
                ->setPublishedAt(new \DateTimeImmutable('-1 minute'));
            $entry->synchronizePublication();
            $em->persist($entry);
            $em->flush();

            $entryId = $entry->getId();
            if ($entryId === null) {
                throw new \LogicException('The synthetic content entry was not persisted.');
            }

            $client->request('GET', '/news/'.$entry->getSlug());
            self::assertResponseIsSuccessful();

            $html = (string) $client->getResponse()->getContent();
            $crawler = $client->getCrawler();

            foreach ([$title, $subtitle, $excerpt, $seoTitle, $seoDescription] as $payload) {
                self::assertStringNotContainsString($payload, $html);
            }

            self::assertSame($title, $crawler->filter('h1')->text());
            self::assertSame($subtitle, $crawler->filter('.article-subtitle')->text());
            self::assertSame($excerpt, $crawler->filter('.article-lead')->text());
            self::assertStringStartsWith($seoTitle, $crawler->filter('title')->text());
            self::assertSame($seoTitle, $crawler->filter('meta[property="og:title"]')->attr('content'));
            self::assertSame($seoDescription, $crawler->filter('meta[name="description"]')->attr('content'));
            self::assertSame($seoDescription, $crawler->filter('meta[property="og:description"]')->attr('content'));

            self::assertSame(0, $crawler->filter('img[onerror], svg[onload]')->count());
            $jsonLd = $crawler->filter('script[type="application/ld+json"]');
            self::assertCount(1, $jsonLd);
            $schema = json_decode($jsonLd->text(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($schema);
            self::assertSame($title, $schema['headline']);
            self::assertSame($seoDescription, $schema['description']);
        } finally {
            $cleanup = $this->em($client);
            $cleanup->clear();

            if ($entryId !== null) {
                $entry = $cleanup->find(ContentEntry::class, $entryId);
                if ($entry instanceof ContentEntry) {
                    $cleanup->remove($entry);
                }
            }

            if ($authorId !== null) {
                $author = $cleanup->find(User::class, $authorId);
                if ($author instanceof User) {
                    $cleanup->remove($author);
                }
            }

            $cleanup->flush();
        }
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}

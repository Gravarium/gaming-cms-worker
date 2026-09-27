<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicContentFeedSafetyTest extends WebTestCase
{
    public function testNewsRssAndJsonFeedsPreserveHostileValuesAsData(): void
    {
        $client = static::createClient();
        $authorId = null;
        $entryId = null;
        $tagSlug = null;

        try {
            $em = $this->em($client);
            $author = (new User())
                ->setEmail('public-content-feed-'.bin2hex(random_bytes(6)).'@example.test')
                ->setDisplayName('Public content feed')
                ->setPermissions([])
                ->setPassword('unused-test-hash')
                ->verifyEmail();
            $tag = (new ContentTag())
                ->setName('placeholder')
                ->setSlug('public-content-feed-'.bin2hex(random_bytes(6)));
            $em->persist($author);
            $em->persist($tag);
            $em->flush();

            $authorId = $author->getId();
            $tagSlug = $tag->getSlug();
            if ($authorId === null || $tag->getId() === null) {
                throw new \LogicException('The synthetic news feed fixture was not persisted.');
            }

            $suffix = bin2hex(random_bytes(6));
            $title = '"><script>alert("feed-title-'.$suffix.'")</script>';
            $excerpt = '<img src=x onerror=alert("feed-excerpt-'.$suffix.'")>';
            $tagName = '"><svg onload=alert("feed-tag-'.$suffix.'")>';

            $tag->setName($tagName);
            $entry = (new ContentEntry())
                ->setAuthor($author)
                ->setType(ContentEntry::TYPE_NEWS)
                ->setTitle($title)
                ->setSlug('public-content-feed-entry-'.$suffix)
                ->setExcerpt($excerpt)
                ->setBody('Safe public feed fixture '.$suffix)
                ->setStatus(ContentEntry::STATUS_PUBLISHED)
                ->setPublishedAt(new \DateTimeImmutable('-1 minute'))
                ->addTag($tag);
            $entry->synchronizePublication();
            $em->persist($entry);
            $em->flush();

            $entryId = $entry->getId();
            if ($entryId === null) {
                throw new \LogicException('The synthetic news feed entry was not persisted.');
            }

            $client->request('GET', '/feeds/news.xml');
            self::assertResponseIsSuccessful();
            self::assertSame('application/rss+xml; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
            $rssBody = (string) $client->getResponse()->getContent();
            foreach ([$title, $excerpt, $tagName] as $payload) {
                self::assertStringNotContainsString($payload, $rssBody);
            }

            $rss = new \DOMDocument();
            self::assertTrue($rss->loadXML($rssBody, LIBXML_NONET | LIBXML_NOBLANKS));
            $rssItem = null;
            foreach ($rss->getElementsByTagName('item') as $item) {
                if (!$item instanceof \DOMElement) {
                    continue;
                }
                $itemTitle = $item->getElementsByTagName('title')->item(0)?->textContent;
                if ($itemTitle === $title) {
                    $rssItem = $item;
                    break;
                }
            }

            self::assertInstanceOf(\DOMElement::class, $rssItem);
            self::assertSame($excerpt, $rssItem->getElementsByTagName('description')->item(0)?->textContent);
            $categories = $rssItem->getElementsByTagName('category');
            self::assertCount(1, $categories);
            self::assertSame($tagName, $categories->item(0)?->textContent);
            self::assertSame(0, $rssItem->getElementsByTagName('script')->length);
            self::assertSame(0, $rssItem->getElementsByTagName('img')->length);
            self::assertSame(0, $rssItem->getElementsByTagName('svg')->length);

            $client->request('GET', '/feeds/news.json');
            self::assertResponseIsSuccessful();
            self::assertStringStartsWith('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
            $jsonFeed = json_decode((string) $client->getResponse()->getContent(), false, 512, JSON_THROW_ON_ERROR);
            self::assertInstanceOf(\stdClass::class, $jsonFeed);
            self::assertIsArray($jsonFeed->items);

            $jsonItem = null;
            foreach ($jsonFeed->items as $item) {
                if ($item instanceof \stdClass && ($item->title ?? null) === $title) {
                    $jsonItem = $item;
                    break;
                }
            }

            self::assertInstanceOf(\stdClass::class, $jsonItem);
            self::assertSame($excerpt, $jsonItem->summary ?? null);
            self::assertSame([$tagName], $jsonItem->tags ?? null);
        } finally {
            $cleanup = $this->em($client);
            $cleanup->clear();

            if ($entryId !== null) {
                $entry = $cleanup->find(ContentEntry::class, $entryId);
                if ($entry instanceof ContentEntry) {
                    $entry->clearTags();
                    $cleanup->remove($entry);
                }
            }

            if ($tagSlug !== null) {
                $tag = $cleanup->getRepository(ContentTag::class)->findOneBy(['slug' => $tagSlug]);
                if ($tag instanceof ContentTag) {
                    $cleanup->remove($tag);
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

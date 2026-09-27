<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PublicNewsIndexSafetyTest extends WebTestCase
{
    public function testNewsIndexEscapesStoredEntryAndTagText(): void
    {
        $client = static::createClient();
        $authorId = null;
        $entryId = null;
        $tagSlug = null;

        try {
            $em = $this->em($client);
            $author = (new User())
                ->setEmail('public-news-index-'.bin2hex(random_bytes(6)).'@example.test')
                ->setDisplayName('Public news index')
                ->setPermissions([])
                ->setPassword('unused-test-hash')
                ->verifyEmail();
            $tag = (new ContentTag())
                ->setName('placeholder')
                ->setSlug('public-news-index-'.bin2hex(random_bytes(6)));
            $em->persist($author);
            $em->persist($tag);
            $em->flush();

            $authorId = $author->getId();
            $tagSlug = $tag->getSlug();
            if ($authorId === null || $tag->getId() === null) {
                throw new \LogicException('The synthetic news index fixture was not persisted.');
            }

            $suffix = bin2hex(random_bytes(6));
            $title = '"><script>alert("news-index-title-'.$suffix.'")</script>';
            $subtitle = '<img src=x onerror=alert("news-index-subtitle-'.$suffix.'")>';
            $excerpt = '"><svg onload=alert("news-index-excerpt-'.$suffix.'")></svg>';
            $tagName = '"><script>alert("news-index-tag-'.$suffix.'")</script>';
            $tag->setName($tagName);

            $entry = (new ContentEntry())
                ->setAuthor($author)
                ->setType(ContentEntry::TYPE_NEWS)
                ->setTitle($title)
                ->setSubtitle($subtitle)
                ->setSlug('public-news-index-entry-'.$suffix)
                ->setExcerpt($excerpt)
                ->setBody('Safe public news index fixture '.$suffix)
                ->setStatus(ContentEntry::STATUS_PUBLISHED)
                ->setPublishedAt(new \DateTimeImmutable('-1 minute'))
                ->setPinned(true)
                ->addTag($tag);
            $entry->synchronizePublication();
            $em->persist($entry);
            $em->flush();

            $entryId = $entry->getId();
            if ($entryId === null) {
                throw new \LogicException('The synthetic news entry was not persisted.');
            }

            $crawler = $client->request('GET', '/news');
            self::assertResponseIsSuccessful();

            $html = (string) $client->getResponse()->getContent();
            foreach ([$title, $subtitle, $excerpt, $tagName] as $payload) {
                self::assertStringNotContainsString($payload, $html);
            }

            $cards = $crawler->filter('.news-grid article')->reduce(
                static function (Crawler $node) use ($entry, $tagSlug): bool {
                    return $node->filter('h2 a[href="/news/'.$entry->getSlug().'"]')->count() === 1
                        && $node->filter('a[href="/news/tag/'.$tagSlug.'"]')->count() === 1;
                },
            );
            self::assertCount(1, $cards);
            $card = $cards->first();
            self::assertSame($title, $card->filter('h2 a')->text());
            self::assertSame($subtitle, $card->filter('p strong')->text());
            self::assertSame($excerpt, $card->filter('p')->eq(2)->text());
            self::assertSame('#'.$tagName, $card->filter('a[href="/news/tag/'.$tagSlug.'"]')->text());
            self::assertSame(0, $card->filter('script, img[onerror], svg[onload]')->count());
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

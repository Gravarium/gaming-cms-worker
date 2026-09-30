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

final class PublicContentTagRenderingTest extends WebTestCase
{
    public function testPublicTagArchiveEscapesStoredTagAndEntryText(): void
    {
        $client = static::createClient();
        $authorId = null;
        $entryId = null;
        $tagSlug = null;

        try {
            $em = $this->em($client);
            $author = (new User())
                ->setEmail('public-tag-rendering-'.bin2hex(random_bytes(6)).'@example.test')
                ->setDisplayName('Public tag rendering')
                ->setPermissions([])
                ->setPassword('unused-test-hash')
                ->verifyEmail();
            $tag = (new ContentTag())
                ->setName('placeholder')
                ->setSlug('public-tag-rendering-'.bin2hex(random_bytes(6)))
                ->setDescription('placeholder');
            $em->persist($author);
            $em->persist($tag);
            $em->flush();

            $authorId = $author->getId();
            $tagSlug = $tag->getSlug();
            if ($authorId === null || $tag->getId() === null) {
                throw new \LogicException('The synthetic tag fixture was not persisted.');
            }

            $suffix = bin2hex(random_bytes(6));
            $tagName = '"><script>alert("tag-name-'.$suffix.'")</script>';
            $tagDescription = '<img src=x onerror=alert("tag-description-'.$suffix.'")>';
            $entryTitle = '"><script>alert("archive-title-'.$suffix.'")</script>';
            $subtitle = '<svg onload=alert("entry-subtitle-'.$suffix.'")></svg>';
            $excerpt = '"><img src=x onerror=alert("entry-excerpt-'.$suffix.'")>';

            $tag->setName($tagName)->setDescription($tagDescription);
            $entry = (new ContentEntry())
                ->setAuthor($author)
                ->setType(ContentEntry::TYPE_NEWS)
                ->setTitle($entryTitle)
                ->setSubtitle($subtitle)
                ->setSlug('public-tag-entry-'.$suffix)
                ->setExcerpt($excerpt)
                ->setBody('Safe tagged content fixture '.$suffix)
                ->setStatus(ContentEntry::STATUS_PUBLISHED)
                ->setPublishedAt(new \DateTimeImmutable('-1 minute'))
                ->addTag($tag);
            $entry->synchronizePublication();
            $em->persist($entry);
            $em->flush();

            $entryId = $entry->getId();
            if ($entryId === null) {
                throw new \LogicException('The synthetic content entry was not persisted.');
            }

            $crawler = $client->request('GET', '/content/tag/'.$tagSlug);
            self::assertResponseIsSuccessful();

            $html = (string) $client->getResponse()->getContent();
            foreach ([$tagName, $tagDescription, $entryTitle, $subtitle, $excerpt] as $payload) {
                self::assertStringNotContainsString($payload, $html);
            }

            self::assertStringContainsString($tagName, $crawler->filter('h1')->text());
            self::assertSame($tagDescription, $crawler->filter('main.dashboard p.muted')->eq(0)->text());
            self::assertSame(1, $crawler->filter('.news-grid article')->count());
            $card = $crawler->filter('.news-grid article');
            self::assertSame($entryTitle, $card->filter('h2')->text());
            self::assertSame($subtitle, $card->filter('strong')->text());
            self::assertSame($excerpt, $card->filter('p')->eq(2)->text());
            self::assertSame(0, $crawler->filter('img[onerror], svg[onload]')->count());

            $scriptBodies = $crawler->filter('script')->each(
                static fn (Crawler $script): string => $script->text(),
            );
            foreach ($scriptBodies as $scriptBody) {
                self::assertStringNotContainsString('tag-name-'.$suffix, $scriptBody);
                self::assertStringNotContainsString('archive-title-'.$suffix, $scriptBody);
            }
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

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicContentSearchSafetyTest extends WebTestCase
{
    public function testSearchQueryAndMatchingContentRemainInertText(): void
    {
        $client = static::createClient();
        $authorId = null;
        $entryId = null;

        try {
            $em = $this->em($client);
            $author = (new User())
                ->setEmail('public-content-search-'.bin2hex(random_bytes(6)).'@example.test')
                ->setDisplayName('Public content search')
                ->setPermissions([])
                ->setPassword('unused-test-hash')
                ->verifyEmail();
            $em->persist($author);
            $em->flush();

            $authorId = $author->getId();
            if ($authorId === null) {
                throw new \LogicException('The synthetic search author was not persisted.');
            }

            $suffix = bin2hex(random_bytes(6));
            $query = '"><script>alert("content-search-'.$suffix.'")</script>';
            $excerpt = '<img src=x onerror=alert("content-search-excerpt-'.$suffix.'")>';
            $entry = (new ContentEntry())
                ->setAuthor($author)
                ->setType(ContentEntry::TYPE_NEWS)
                ->setTitle($query)
                ->setSlug('public-content-search-'.$suffix)
                ->setExcerpt($excerpt)
                ->setBody('Safe public search fixture '.$suffix)
                ->setStatus(ContentEntry::STATUS_PUBLISHED)
                ->setPublishedAt(new \DateTimeImmutable('-1 minute'));
            $entry->synchronizePublication();
            $em->persist($entry);
            $em->flush();

            $entryId = $entry->getId();
            if ($entryId === null) {
                throw new \LogicException('The synthetic search entry was not persisted.');
            }

            $client->request('GET', '/search', ['q' => $query]);
            self::assertResponseIsSuccessful();

            $html = (string) $client->getResponse()->getContent();
            $crawler = $client->getCrawler();
            self::assertStringNotContainsString($query, $html);
            self::assertStringNotContainsString($excerpt, $html);
            self::assertSame($query, $crawler->filter('input[name="q"]')->attr('value'));
            self::assertStringContainsString($query, $crawler->filter('main.dashboard .muted')->text());

            $results = $crawler->filter('.news-grid article');
            self::assertCount(1, $results);
            self::assertSame($query, $results->filter('h2')->text());
            self::assertSame($excerpt, $results->filter('p')->text());
            self::assertSame(0, $results->filter('script, img[onerror], svg[onload]')->count());
            self::assertSame(0, $crawler->filter('main.dashboard img[onerror], main.dashboard svg[onload]')->count());
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

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicNewsAtomFeedTest extends WebTestCase
{
    public function testAtomFeedContainsOnlyBoundedPublicNewsAndSupportsConditionalRequests(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $author = (new User())->setEmail('news-atom-'.$suffix.'@example.test')
            ->setDisplayName('News Atom author')->verifyEmail();
        $category = (new Category())->setName('Atom '.$suffix)->setSlug('atom-'.$suffix);
        $entities = [$author, $category];

        for ($index = 1; $index <= 52; ++$index) {
            $title = $index === 52 ? 'Atom & public '.$suffix : 'Atom public '.$suffix.' '.$index;
            $entry = $this->entry($author, $category, $title, 'atom-'.$suffix.'-'.$index, ContentEntry::STATUS_PUBLISHED);
            if ($index === 52) {
                $entry->setPinned(true);
            }
            $entities[] = $entry;
        }
        $entities[] = $this->entry($author, $category, 'Atom draft '.$suffix, 'atom-'.$suffix.'-draft', ContentEntry::STATUS_DRAFT);
        $entities[] = $this->entry($author, $category, 'Atom unlisted '.$suffix, 'atom-'.$suffix.'-unlisted', ContentEntry::STATUS_PUBLISHED, true);

        foreach ($entities as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $client->request('GET', '/feeds/news.atom');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/atom+xml; charset=UTF-8');
        self::assertStringContainsString('max-age=', (string) $client->getResponse()->headers->get('Cache-Control'));
        $xml = (string) $client->getResponse()->getContent();
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $xpath = new \DOMXPath($document);
        self::assertSame('http://www.w3.org/2005/Atom', $xpath->query('/*')->item(0)?->namespaceURI);
        self::assertSame(50, $xpath->query('//*[local-name()="entry"]')->length);
        self::assertSame(1, $xpath->query('//*[local-name()="title" and text()="Atom & public '.$suffix.'"]')->length);
        self::assertSame(0, $xpath->query('//*[contains(text(), "draft")]')->length);
        self::assertSame(0, $xpath->query('//*[contains(text(), "unlisted")]')->length);
        self::assertStringNotContainsString('Private body', $xml);

        $etag = $client->getResponse()->headers->get('ETag');
        self::assertIsString($etag);
        $client->request('GET', '/feeds/news.atom', server: ['HTTP_IF_NONE_MATCH' => $etag]);
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
            ->setBody('Private body for '.$title)
            ->setStatus($status)
            ->setUnlisted($unlisted);
        $entry->synchronizePublication();

        return $entry;
    }
}

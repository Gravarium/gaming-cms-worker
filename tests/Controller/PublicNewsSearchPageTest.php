<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicNewsSearchPageTest extends WebTestCase
{
    public function testSearchPageListsOnlyPublicNewsAndProvidesAnAccessibleSearchForm(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $author = (new User())->setEmail('news-page-search-'.$suffix.'@example.test')
            ->setDisplayName('News page search author')->verifyEmail();
        $category = (new Category())->setName('Search '.$suffix)->setSlug('search-'.$suffix);
        $entities = [$author, $category];

        for ($index = 1; $index <= 3; ++$index) {
            $entities[] = $this->entry($author, $category, 'searchpage-'.$suffix.' result '.$index, 'searchpage-'.$suffix.'-'.$index, ContentEntry::STATUS_PUBLISHED);
        }
        $entities[] = $this->entry($author, $category, 'searchpage-'.$suffix.' draft', 'searchpage-'.$suffix.'-draft', ContentEntry::STATUS_DRAFT);
        $entities[] = $this->entry($author, $category, 'searchpage-'.$suffix.' unlisted', 'searchpage-'.$suffix.'-unlisted', ContentEntry::STATUS_PUBLISHED, true);

        foreach ($entities as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $client->request('GET', '/news/search', ['q' => 'searchpage-'.$suffix]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertSelectorExists('form[role="search"][method="get"]');
        self::assertSelectorExists('input[name="q"][type="search"][minlength="2"][maxlength="80"]');
        self::assertSelectorTextSame('h2', 'Suchergebnisse für „searchpage-'.$suffix.'“');
        self::assertCount(3, $client->getCrawler()->filter('main ul li a'));
        self::assertStringNotContainsString(' draft', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('unlisted', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Private body', (string) $client->getResponse()->getContent());
    }

    public function testShortQueriesSkipResultsAndInvalidQueryShapesAreRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/news/search', ['q' => 'x']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[role="search"]');
        self::assertSelectorNotExists('h2');

        $client->request('GET', '/news/search', ['q' => str_repeat('x', 81)]);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/news/search', ['q' => ['news', 'page']]);
        self::assertResponseStatusCodeSame(404);
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
            ->setTitle($title)
            ->setSlug($slug)
            ->setBody('Private body for '.$title)
            ->setStatus($status)
            ->setUnlisted($unlisted);
        $entry->setType(ContentEntry::TYPE_NEWS);
        $entry->synchronizePublication();

        return $entry;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class PublicContentSearchFilterTest extends WebTestCase
{
    public function testSearchCombinesTypeAndCategoryAndKeepsOnlyPublicMatches(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $author = $this->author($client, $suffix);
        $firstCategory = (new Category())->setName('First category '.$suffix)->setSlug('first-category-'.$suffix);
        $secondCategory = (new Category())->setName('Second category '.$suffix)->setSlug('second-category-'.$suffix);
        $entityManager = $this->entityManager($client);
        $entityManager->persist($firstCategory);
        $entityManager->persist($secondCategory);
        $entityManager->flush();

        $visibleNews = $this->entry($author, $firstCategory, ContentEntry::TYPE_NEWS, 'filterneedle-news-'.$suffix, 'filterneedle visible news', 'Public news body');
        $visiblePage = $this->entry($author, $firstCategory, ContentEntry::TYPE_PAGE, 'filterneedle-page-'.$suffix, 'Visible page '.$suffix, 'filterneedle page body');
        $otherCategoryNews = $this->entry($author, $secondCategory, ContentEntry::TYPE_NEWS, 'filterneedle-other-'.$suffix, 'Other category news '.$suffix, 'filterneedle other category body');
        $draft = $this->entry($author, $firstCategory, ContentEntry::TYPE_NEWS, 'filterneedle-draft-'.$suffix, 'Hidden draft '.$suffix, 'filterneedle draft body', ContentEntry::STATUS_DRAFT);
        $unlisted = $this->entry($author, $firstCategory, ContentEntry::TYPE_NEWS, 'filterneedle-unlisted-'.$suffix, 'Hidden unlisted '.$suffix, 'filterneedle unlisted body', ContentEntry::STATUS_PUBLISHED, true);
        $expired = $this->entry($author, $firstCategory, ContentEntry::TYPE_NEWS, 'filterneedle-expired-'.$suffix, 'Hidden expired '.$suffix, 'filterneedle expired body', ContentEntry::STATUS_PUBLISHED, false, new \DateTimeImmutable('-1 hour'));

        foreach ([$visibleNews, $visiblePage, $otherCategoryNews, $draft, $unlisted, $expired] as $entry) {
            $entityManager->persist($entry);
        }
        $entityManager->flush();

        $client->request('GET', '/search', [
            'q' => 'filterneedle',
            'type' => ContentEntry::TYPE_NEWS,
            'category' => $firstCategory->getSlug(),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $visibleNews->getTitle());
        self::assertSelectorTextNotContains('body', $visiblePage->getTitle());
        self::assertSelectorTextNotContains('body', $otherCategoryNews->getTitle());
        self::assertSelectorTextNotContains('body', $draft->getTitle());
        self::assertSelectorTextNotContains('body', $unlisted->getTitle());
        self::assertSelectorTextNotContains('body', $expired->getTitle());
        self::assertSame('news', $client->getCrawler()->filter('select[name="type"] option[selected]')->attr('value'));
        self::assertSame($firstCategory->getSlug(), $client->getCrawler()->filter('select[name="category"] option[selected]')->attr('value'));

        $client->request('GET', '/search', [
            'q' => 'filterneedle',
            'type' => ContentEntry::TYPE_PAGE,
            'category' => $firstCategory->getSlug(),
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $visiblePage->getTitle());
        self::assertSelectorTextNotContains('body', $visibleNews->getTitle());
    }

    public function testSearchRejectsMalformedUnknownAndOverlongFilters(): void
    {
        $client = static::createClient();
        $client->request('GET', '/search', ['q' => 'filterneedle', 'type' => ['news']]);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/search', ['q' => 'filterneedle', 'category' => ['first-category']]);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/search', ['q' => 'filterneedle', 'type' => 'video']);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/search', ['q' => 'filterneedle', 'category' => str_repeat('x', 121)]);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/search', ['q' => 'filterneedle', 'category' => 'unknown-category']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testSearchEtagChangesWithFiltersEvenWhenBothResultSetsAreEmpty(): void
    {
        $client = static::createClient();
        $query = 'no-results-'.bin2hex(random_bytes(5));

        $client->request('GET', '/search', ['q' => $query, 'type' => ContentEntry::TYPE_NEWS]);
        self::assertResponseIsSuccessful();
        $newsEtag = $client->getResponse()->headers->get('ETag');

        $client->request('GET', '/search', ['q' => $query, 'type' => ContentEntry::TYPE_PAGE]);
        self::assertResponseIsSuccessful();
        $pageEtag = $client->getResponse()->headers->get('ETag');

        self::assertIsString($newsEtag);
        self::assertIsString($pageEtag);
        self::assertNotSame($newsEtag, $pageEtag);
    }

    private function author(KernelBrowser $client, string $suffix): User
    {
        $author = (new User())
            ->setEmail('content-search-'.$suffix.'@example.test')
            ->setDisplayName('Content search '.$suffix)
            ->verifyEmail();
        $this->entityManager($client)->persist($author);
        $this->entityManager($client)->flush();

        return $author;
    }

    private function entry(
        User $author,
        Category $category,
        string $type,
        string $slug,
        string $title,
        string $body,
        string $status = ContentEntry::STATUS_PUBLISHED,
        bool $unlisted = false,
        ?\DateTimeImmutable $scheduledUnpublishAt = null,
    ): ContentEntry {
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setCategory($category)
            ->setType($type)
            ->setTitle($title)
            ->setSlug($slug)
            ->setBody($body)
            ->setStatus($status)
            ->setUnlisted($unlisted);

        if ($status === ContentEntry::STATUS_PUBLISHED) {
            $entry->setPublishedAt(new \DateTimeImmutable('-1 day'));
        }
        if ($scheduledUnpublishAt !== null) {
            $entry->setScheduledUnpublishAt($scheduledUnpublishAt);
        }

        return $entry;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}

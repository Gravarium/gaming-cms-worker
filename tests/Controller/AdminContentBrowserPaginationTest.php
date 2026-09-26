<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminContentBrowserPaginationTest extends WebTestCase
{
    public function testEveryMatchingEntryIsReachablePastTheLegacyTwoHundredLimit(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $needle = 'content-browser-'.bin2hex(random_bytes(5));
        $entries = $this->persistEntries($client, $admin, $needle, 201);
        $client->loginUser($admin);
        $seen = [];
        $firstPageIds = [];

        for ($page = 1; $page <= 9; ++$page) {
            $crawler = $client->request('GET', '/admin/content/all?'.http_build_query([
                'q' => $needle,
                'page' => $page,
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', '201 Treffer');
            self::assertSelectorTextContains('body', 'Seite '.$page.' von 9');

            $visibleIds = $crawler->filter('tr[data-content-id]')->each(
                static fn (Crawler $row): string => (string) $row->attr('data-content-id'),
            );
            self::assertCount($page === 9 ? 1 : 25, $visibleIds);

            foreach ($visibleIds as $id) {
                self::assertArrayNotHasKey($id, $seen, 'An entry must not appear on more than one page.');
                $seen[$id] = true;
            }

            if ($page === 1) {
                $firstPageIds = $visibleIds;
                $nextUrl = (string) $crawler->filter('nav[aria-label="Seitennavigation"] a[rel="next"]')->attr('href');
                parse_str((string) parse_url($nextUrl, PHP_URL_QUERY), $nextQuery);
                self::assertSame($needle, $nextQuery['q'] ?? null);
                self::assertSame('2', $nextQuery['page'] ?? null);
                self::assertSelectorExists('#content-bulk-form input[name="_token"]');
                self::assertSelectorExists('input[form="content-bulk-form"][name="ids[]"]');
            }
        }

        $expectedIds = [];
        foreach ($entries as $entry) {
            $id = $entry->getId();
            self::assertNotNull($id);
            $expectedIds[] = (string) $id;
        }
        self::assertEqualsCanonicalizing($expectedIds, array_keys($seen));

        $client->request('GET', '/admin/content/all?'.http_build_query(['q' => $needle, 'page' => 1]));
        self::assertResponseIsSuccessful();
        $repeatedIds = $client->getCrawler()->filter('tr[data-content-id]')->each(
            static fn (Crawler $row): string => (string) $row->attr('data-content-id'),
        );
        self::assertSame($firstPageIds, $repeatedIds, 'Unchanged results must keep the same page order.');

        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        $robots = (string) $client->getResponse()->headers->get('X-Robots-Tag');
        self::assertStringContainsString('noindex', $robots);
        self::assertStringContainsString('nofollow', $robots);
    }

    public function testIndexLinkAndPaginationPreserveAllExistingFilters(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $suffix = bin2hex(random_bytes(5));
        $needle = 'filtered-content-'.$suffix;
        $categorySlug = 'content-category-'.$suffix;
        $tagSlug = 'content-tag-'.$suffix;
        $category = (new Category())->setName('Category '.$suffix)->setSlug($categorySlug);
        $tag = (new ContentTag())->setName('Tag '.$suffix)->setSlug($tagSlug);
        $this->em($client)->persist($category);
        $this->em($client)->persist($tag);
        $this->em($client)->flush();

        $this->persistEntries($client, $admin, $needle, 26, $category, $tag);
        $this->persistEntries(
            $client,
            $admin,
            $needle.'-outside',
            1,
            null,
            null,
            ContentEntry::STATUS_REVIEW,
            ContentEntry::TYPE_PAGE,
        );

        $filters = [
            'q' => $needle,
            'status' => ContentEntry::STATUS_DRAFT,
            'type' => ContentEntry::TYPE_NEWS,
            'category' => $categorySlug,
            'tag' => $tagSlug,
        ];
        $client->loginUser($admin);
        $index = $client->request('GET', '/admin/content?'.http_build_query($filters));

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(26, 'table tbody tr');
        $browserUrl = (string) $index->selectLink('Vollständige Inhaltsliste')->attr('href');
        self::assertSame('/admin/content/all', parse_url($browserUrl, PHP_URL_PATH));
        parse_str((string) parse_url($browserUrl, PHP_URL_QUERY), $linkedFilters);
        foreach ($filters as $key => $value) {
            self::assertSame($value, $linkedFilters[$key] ?? null);
        }

        $firstPage = $client->request('GET', $browserUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '26 Treffer');
        self::assertSelectorCount(25, 'tr[data-content-id]');

        $nextUrl = (string) $firstPage->filter('nav[aria-label="Seitennavigation"] a[rel="next"]')->attr('href');
        parse_str((string) parse_url($nextUrl, PHP_URL_QUERY), $nextQuery);
        foreach ($filters as $key => $value) {
            self::assertSame($value, $nextQuery[$key] ?? null);
        }
        self::assertSame('2', $nextQuery['page'] ?? null);

        $client->request('GET', $nextUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Seite 2 von 2');
        self::assertSelectorCount(1, 'tr[data-content-id]');

        $client->request('GET', '/admin/content/all?'.http_build_query($filters + ['page' => 999]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Seite 2 von 2');
        self::assertSelectorCount(1, 'tr[data-content-id]');
    }

    public function testTrashedEntriesKeepTheExistingCsrfProtectedRecoveryActions(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $needle = 'trashed-content-'.bin2hex(random_bytes(5));
        $entries = $this->persistEntries($client, $admin, $needle, 1, null, null, ContentEntry::STATUS_TRASHED);
        $entryId = $entries[0]->getId();
        self::assertNotNull($entryId);
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/content/all?'.http_build_query([
            'q' => $needle,
            'status' => ContentEntry::STATUS_TRASHED,
        ]));

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, 'tr[data-content-id]');
        self::assertNotEmpty($crawler->filter('form[action="/admin/content/'.$entryId.'/restore-trash"] input[name="_token"]')->attr('value'));
        self::assertNotEmpty($crawler->filter('form[action="/admin/content/'.$entryId.'/purge"] input[name="_token"]')->attr('value'));
    }

    public function testMalformedPageValuesAreRejected(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, [CmsPermission::CONTENT]));

        foreach (['page=0', 'page=-1', 'page=not-a-number', 'page=99999999', 'page%5B0%5D=1'] as $query) {
            $client->request('GET', '/admin/content/all?'.$query);
            self::assertResponseStatusCodeSame(400);
        }
    }

    public function testContentPermissionIsRequired(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/content/all');
        self::assertResponseRedirects('/login');

        $client->loginUser($this->user($client, [CmsPermission::ACCESS]));
        $client->request('GET', '/admin/content/all');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return list<ContentEntry>
     */
    private function persistEntries(
        KernelBrowser $client,
        User $author,
        string $needle,
        int $count,
        ?Category $category = null,
        ?ContentTag $tag = null,
        string $status = ContentEntry::STATUS_DRAFT,
        string $type = ContentEntry::TYPE_NEWS,
    ): array {
        $entries = [];
        for ($index = 0; $index < $count; ++$index) {
            $suffix = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $entry = (new ContentEntry())
                ->setAuthor($author)
                ->setType($type)
                ->setTitle($needle.' '.$suffix)
                ->setSlug($needle.'-'.$suffix)
                ->setBody('Body '.$needle.' '.$suffix);
            if ($category !== null) {
                $entry->setCategory($category);
            }
            if ($tag !== null) {
                $entry->addTag($tag);
            }
            if ($status === ContentEntry::STATUS_TRASHED) {
                $entry->trash();
            } elseif ($status !== ContentEntry::STATUS_DRAFT) {
                $entry->setStatus($status);
                $entry->synchronizePublication();
            }
            $this->em($client)->persist($entry);
            $entries[] = $entry;
        }
        $this->em($client)->flush();

        return $entries;
    }

    /**
     * @param list<string> $permissions
     */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('content-browser-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Content browser test')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}

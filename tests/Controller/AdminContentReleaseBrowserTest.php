<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentRelease;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminContentReleaseBrowserTest extends WebTestCase
{
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = 'release-browser-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            $connection = self::$kernel->getContainer()->get(EntityManagerInterface::class)->getConnection();
            $connection->executeStatement(
                'DELETE FROM content_release_entry WHERE content_release_id IN (SELECT id FROM content_release WHERE name LIKE ?)',
                [$this->marker.'-%'],
            );
            $connection->executeStatement('DELETE FROM content_release WHERE name LIKE ?', [$this->marker.'-%']);
            $connection->executeStatement('DELETE FROM cms_user WHERE email LIKE ?', [$this->marker.'-%@example.test']);
        }

        parent::tearDown();
    }

    public function testListSearchesFiltersAndTraversesEveryStablePage(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, true);
        $client->loginUser($admin);
        for ($number = 1; $number <= 53; ++$number) {
            $this->release($client, $admin, sprintf('draft-%02d', $number));
        }
        $this->release($client, $admin, 'cancelled', ContentRelease::STATUS_CANCELLED);
        $this->em($client)->flush();

        $expectedIds = $this->releaseIds($client, ContentRelease::STATUS_DRAFT);
        self::assertCount(53, $expectedIds);

        $pageOne = $client->request('GET', $this->listingUrl(['q' => $this->marker, 'status' => ContentRelease::STATUS_DRAFT]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="status"]', '1–25 von 53 Releases');
        self::assertSame(array_slice($expectedIds, 0, 25), $this->idsFrom($pageOne));

        $pageTwo = $client->request('GET', $this->listingUrl(['q' => $this->marker, 'status' => ContentRelease::STATUS_DRAFT, 'page' => 2]));
        self::assertSelectorTextContains('[role="status"]', '26–50 von 53 Releases');
        self::assertSame(array_slice($expectedIds, 25, 25), $this->idsFrom($pageTwo));

        $next = $pageTwo->filter('a[rel="next"]')->attr('href');
        self::assertSame([
            'q' => $this->marker,
            'status' => ContentRelease::STATUS_DRAFT,
            'page' => '3',
        ], $this->queryParameters($next));
        $previous = $pageTwo->filter('a[rel="prev"]')->attr('href');
        self::assertSame([
            'q' => $this->marker,
            'status' => ContentRelease::STATUS_DRAFT,
            'page' => '1',
        ], $this->queryParameters($previous));

        $pageThree = $client->request('GET', $this->listingUrl(['q' => $this->marker, 'status' => ContentRelease::STATUS_DRAFT, 'page' => 3]));
        self::assertSelectorTextContains('[role="status"]', '51–53 von 53 Releases');
        self::assertSame(array_slice($expectedIds, 50, 25), $this->idsFrom($pageThree));

        $pastEnd = $client->request('GET', $this->listingUrl(['q' => $this->marker, 'status' => ContentRelease::STATUS_DRAFT, 'page' => 99]));
        self::assertSelectorTextContains('[role="status"]', '51–53 von 53 Releases');
        self::assertSame(array_slice($expectedIds, 50, 25), $this->idsFrom($pastEnd));

        $cancelled = $client->request('GET', $this->listingUrl(['q' => $this->marker, 'status' => ContentRelease::STATUS_CANCELLED]));
        self::assertSelectorTextContains('[role="status"]', '1–1 von 1 Releases');
        self::assertCount(1, $this->idsFrom($cancelled));

        $empty = $client->request('GET', $this->listingUrl(['q' => 'no-release-matches-this', 'status' => ContentRelease::STATUS_DRAFT]));
        self::assertSelectorTextContains('[role="status"]', 'Keine passenden Releases.');
        self::assertSelectorTextContains('tbody', 'Keine Releases für diese Filter.');
        self::assertCount(0, $this->idsFrom($empty));
    }

    public function testListingAndEditFormsKeepTheCurrentFilterState(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, true);
        $client->loginUser($admin);
        for ($number = 1; $number <= 27; ++$number) {
            $this->release($client, $admin, sprintf('form-%02d', $number));
        }
        $this->em($client)->flush();

        $crawler = $client->request('GET', $this->listingUrl([
            'q' => $this->marker,
            'status' => ContentRelease::STATUS_DRAFT,
            'page' => 2,
        ]));
        $parameters = [
            'q' => $this->marker,
            'status' => ContentRelease::STATUS_DRAFT,
            'page' => '2',
        ];
        self::assertSame($parameters, $this->queryParameters($crawler->filter('.topbar a.button-link')->attr('href')));

        $editHref = $crawler->filter('tbody tr[data-release-id] a')->first()->attr('href');
        self::assertSame($parameters, $this->queryParameters($editHref));
        $editCrawler = $client->request('GET', $editHref);
        self::assertResponseIsSuccessful();
        self::assertSame($parameters, $this->queryParameters($editCrawler->filter('form.content-form')->attr('action')));
        self::assertSame($parameters, $this->queryParameters($editCrawler->filter('.topbar a.brand')->attr('href')));

        $newCrawler = $client->request('GET', $this->listingUrl($parameters));
        $newHref = $newCrawler->filter('.topbar a.button-link')->attr('href');
        $newForm = $client->request('GET', $newHref);
        self::assertResponseIsSuccessful();
        self::assertSame($parameters, $this->queryParameters($newForm->filter('form.content-form')->attr('action')));
    }

    public function testMalformedFiltersReturnGenericUncachedBadRequest(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, true));
        $malformed = [
            ['q' => ['secret-value']],
            ['status' => ['draft']],
            ['status' => 'unknown-status'],
            ['page' => ['2']],
            ['page' => '0'],
            ['page' => '01'],
            ['page' => '999999999999999999999999'],
            ['q' => str_repeat('x', 101)],
        ];

        foreach ($malformed as $query) {
            $client->request('GET', $this->listingUrl($query));
            self::assertResponseStatusCodeSame(400);
            self::assertSame('Ungültige Filterparameter.', $client->getResponse()->getContent());
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringNotContainsString('secret-value', (string) $client->getResponse()->getContent());
            self::assertStringStartsWith('text/plain', (string) $client->getResponse()->headers->get('Content-Type'));
        }
    }

    public function testContentPermissionIsRequiredForReleaseBrowser(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, false));

        $client->request('GET', '/admin/content/releases');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCancelledReleaseHasNoPublishAction(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, true);
        $release = $this->release($client, $admin, 'cancelled-action', ContentRelease::STATUS_CANCELLED);
        $this->em($client)->flush();
        $releaseId = $release->getId();
        self::assertNotNull($releaseId);
        $client->loginUser($admin);

        $crawler = $client->request('GET', $this->listingUrl([
            'q' => $this->marker,
            'status' => ContentRelease::STATUS_CANCELLED,
        ]));

        self::assertCount(0, $crawler->filter('form[action*="/'.$releaseId.'/publish"]'));
        self::assertCount(1, $crawler->filter('form[action*="/'.$releaseId.'/delete"]'));
    }

    public function testEmptyReleasePublishKeepsDraftAndReturnsToFilteredList(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, true);
        $release = $this->release($client, $admin, 'empty');
        $this->em($client)->flush();
        $releaseId = $release->getId();
        self::assertNotNull($releaseId);
        $client->loginUser($admin);
        $query = ['q' => $this->marker, 'status' => ContentRelease::STATUS_DRAFT];

        $crawler = $client->request('GET', $this->listingUrl($query));
        $publishForm = $crawler->filter('form[action*="/'.$releaseId.'/publish"]');
        $action = $publishForm->attr('action');
        $token = $publishForm->filter('input[name="_token"]')->attr('value');
        $client->request('POST', $action, ['_token' => $token]);

        self::assertResponseRedirects();
        self::assertSame($query, $this->queryParameters((string) $client->getResponse()->headers->get('Location')));
        $client->followRedirect();
        self::assertSelectorTextContains('.notice.text-danger', 'Ein Release ohne veröffentlichbare Inhalte kann nicht veröffentlicht werden.');

        $this->em($client)->clear();
        $stored = $this->em($client)->find(ContentRelease::class, $releaseId);
        self::assertInstanceOf(ContentRelease::class, $stored);
        self::assertSame(ContentRelease::STATUS_DRAFT, $stored->getStatus());
        self::assertNull($stored->getPublishedAt());
    }

    public function testPublishAndDeleteRejectInvalidCsrfWithoutChangingRelease(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, true);
        $release = $this->release($client, $admin, 'csrf');
        $this->em($client)->flush();
        $releaseId = $release->getId();
        self::assertNotNull($releaseId);
        $client->loginUser($admin);
        $query = ['q' => $this->marker, 'status' => ContentRelease::STATUS_DRAFT];
        $crawler = $client->request('GET', $this->listingUrl($query));
        $publishAction = $crawler->filter('form[action*="/'.$releaseId.'/publish"]')->attr('action');
        $deleteAction = $crawler->filter('form[action*="/'.$releaseId.'/delete"]')->attr('action');

        $client->request('POST', $publishAction, ['_token' => 'invalid-token']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $deleteAction, ['_token' => 'invalid-token']);
        self::assertResponseStatusCodeSame(403);

        $this->em($client)->clear();
        $stored = $this->em($client)->find(ContentRelease::class, $releaseId);
        self::assertInstanceOf(ContentRelease::class, $stored);
        self::assertSame(ContentRelease::STATUS_DRAFT, $stored->getStatus());
    }

    public function testDeleteRedirectPreservesFiltersAndRemovesRelease(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, true);
        $release = $this->release($client, $admin, 'delete');
        $this->em($client)->flush();
        $releaseId = $release->getId();
        self::assertNotNull($releaseId);
        $client->loginUser($admin);
        $query = ['q' => $this->marker, 'status' => ContentRelease::STATUS_DRAFT];
        $crawler = $client->request('GET', $this->listingUrl($query));
        $deleteForm = $crawler->filter('form[action*="/'.$releaseId.'/delete"]');
        $client->request('POST', $deleteForm->attr('action'), [
            '_token' => $deleteForm->filter('input[name="_token"]')->attr('value'),
        ]);

        self::assertResponseRedirects();
        self::assertSame($query, $this->queryParameters((string) $client->getResponse()->headers->get('Location')));
        $this->em($client)->clear();
        self::assertNull($this->em($client)->find(ContentRelease::class, $releaseId));
    }

    private function user(KernelBrowser $client, bool $canManageContent): User
    {
        $permissions = $canManageContent ? [CmsPermission::CONTENT] : [];
        $user = (new User())
            ->setEmail($this->marker.'-'.bin2hex(random_bytes(4)).'@example.test')
            ->setDisplayName('Release browser test')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function release(KernelBrowser $client, User $admin, string $suffix, string $status = ContentRelease::STATUS_DRAFT): ContentRelease
    {
        $release = (new ContentRelease())
            ->setName($this->marker.'-'.$suffix)
            ->setCreatedBy($admin)
            ->setStatus($status);
        $this->em($client)->persist($release);

        return $release;
    }

    /** @param array<string, scalar|array<array-key, scalar>> $query */
    private function listingUrl(array $query): string
    {
        return '/admin/content/releases?'.http_build_query($query);
    }

    /** @return list<int> */
    private function releaseIds(KernelBrowser $client, string $status): array
    {
        $ids = $this->em($client)->getConnection()->fetchFirstColumn(
            'SELECT id FROM content_release WHERE name LIKE ? AND status = ? ORDER BY created_at DESC, id DESC',
            [$this->marker.'-%', $status],
        );

        return array_map('intval', $ids);
    }

    /** @return list<int> */
    private function idsFrom(Crawler $crawler): array
    {
        $rows = $crawler->filter('tbody tr[data-release-id]')->extract(['data-release-id']);

        return array_map(static fn (array $row): int => (int) $row[0], $rows);
    }

    /** @return array<string, string> */
    private function queryParameters(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) {
            return [];
        }

        parse_str($query, $parameters);
        return array_map('strval', $parameters);
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
